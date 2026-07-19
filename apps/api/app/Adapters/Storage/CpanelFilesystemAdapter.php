<?php

namespace App\Adapters\Storage;

use App\Domain\Storage\PathJail;
use App\Domain\Storage\PathOutsideJailException;
use App\Domain\Storage\StorageException;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * cPanel filesystem adapter — native PHP only (no shell): mkdir/rename/unlink/scandir,
 * ZipArchive, symlink (docs/SPEC.md §2.1, §11). All managed-project paths are confined
 * by {@see PathJail}; cPorter's own artifact storage is internal and not jailed.
 *
 * Operations on the `current` symlink / `deploy.lock` validate the PARENT against the jail
 * and keep the (symlink) leaf unresolved, so we can read/replace the link itself.
 */
class CpanelFilesystemAdapter implements StorageAdapter
{
    public function __construct(
        private readonly PathJail $jail,
        private readonly int $maxFiles = 50000,
        private readonly int $maxBytes = 268435456,          // 256 MB
        private readonly int $maxUncompressedBytes = 1073741824, // 1 GB
        private readonly int $lockTtl = 900,                 // seconds
    ) {}

    // ── T1.2: artifact storage + extraction ──────────────────────────────────────

    public function putArtifact(string $sourcePath, string $projectSlug): string
    {
        if (! is_file($sourcePath)) {
            throw new StorageException("Artifact source file not found: {$sourcePath}");
        }

        $size = filesize($sourcePath);
        if ($size !== false && $size > $this->maxBytes) {
            throw new StorageException("Artifact exceeds max size ({$size} > {$this->maxBytes} bytes).");
        }

        $dir = storage_path('app/artifacts/'.$this->safeSlug($projectSlug));
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new StorageException("Failed to create artifact directory: {$dir}");
        }

        $dest = $dir.'/'.Str::uuid()->toString().'.zip';

        if (! @rename($sourcePath, $dest)) {
            if (! @copy($sourcePath, $dest)) {
                throw new StorageException("Failed to store artifact at: {$dest}");
            }
            @unlink($sourcePath);
        }

        return $dest;
    }

    public function extractZip(string $archivePath, string $destDir): void
    {
        if (! is_file($archivePath)) {
            throw new StorageException("Archive not found: {$archivePath}");
        }

        $dest = $this->jail->assertInside($destDir);

        if (! is_dir($dest) && ! @mkdir($dest, 0775, true) && ! is_dir($dest)) {
            throw new StorageException("Failed to create extraction directory: {$dest}");
        }
        $dest = realpath($dest) ?: $dest;

        $zip = new ZipArchive;
        $opened = $zip->open($archivePath);
        if ($opened !== true) {
            throw new StorageException("Failed to open zip (code {$opened}): {$archivePath}");
        }

        try {
            if ($zip->numFiles > $this->maxFiles) {
                throw new StorageException("Archive has too many files ({$zip->numFiles} > {$this->maxFiles}).");
            }

            $entryJail = new PathJail([$dest]);
            $totalUncompressed = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || ($stat['name'] ?? '') === '') {
                    throw new StorageException("Unreadable zip entry at index {$i}.");
                }

                $name = $stat['name'];
                if (str_contains($name, "\0")) {
                    throw StorageException::zipSlip($name);
                }

                try {
                    $entryJail->assertInside($dest.'/'.$name);
                } catch (PathOutsideJailException) {
                    throw StorageException::zipSlip($name);
                }

                $totalUncompressed += (int) ($stat['size'] ?? 0);
                if ($totalUncompressed > $this->maxUncompressedBytes) {
                    throw new StorageException("Archive uncompressed size exceeds limit ({$this->maxUncompressedBytes} bytes).");
                }
            }

            if (! $zip->extractTo($dest)) {
                throw new StorageException("Extraction failed into: {$dest}");
            }
        } finally {
            $zip->close();
        }
    }

    // ── T1.3: activation / shared links / prune / lock ───────────────────────────

    public function activate(string $target, string $linkPath): void
    {
        $target = $this->jail->assertInside($target);
        if (! is_dir($target)) {
            throw new StorageException("Activation target is not a directory: {$target}");
        }

        $link = $this->jailedLeaf($linkPath);

        if ($this->trySymlinkSwap($target, $link)) {
            return;
        }

        // Fallback for hosts without symlink support: copy the release into place so that
        // `releases/<id>` stays immutable (docs/SPEC.md §8).
        $this->copySwap($target, $link);
    }

    public function linkShared(string $releaseDir, string $sharedDir, array $sharedPaths): void
    {
        $release = $this->jail->assertInside($releaseDir);
        $shared = $this->jail->assertInside($sharedDir);
        if (! is_dir($shared) && ! @mkdir($shared, 0775, true) && ! is_dir($shared)) {
            throw new StorageException("Failed to create shared directory: {$shared}");
        }

        $releaseJail = new PathJail([$release]);
        $sharedJail = new PathJail([$shared]);

        foreach ($sharedPaths as $entry) {
            [$rel, $type] = $this->sharedEntry($entry);
            if ($rel === '') {
                continue;
            }

            $releasePath = $releaseJail->assertInside($release.'/'.$rel);
            $sharedPath = $sharedJail->assertInside($shared.'/'.$rel);

            // Seed shared/ from what the artifact shipped (first deploy), else create per
            // the declared type: a directory for 'dir', but never an empty file for 'file'
            // — an empty secret/config would let the app boot broken, so require the
            // operator to create it in shared/ first (docs/SPEC.md §12, §17).
            if (! file_exists($sharedPath) && ! is_link($sharedPath)) {
                if (file_exists($releasePath)) {
                    $this->ensureParent($sharedPath);
                    if (! @rename($releasePath, $sharedPath)) {
                        throw new StorageException("Failed to seed shared path: {$rel}");
                    }
                } elseif ($type === 'file') {
                    // Fail before touching the filesystem so a missing shared file leaves no
                    // stray parent directory behind — the operator must create it in shared/.
                    throw new StorageException(
                        "Shared file '{$rel}' is missing — create ".basename($shared)."/{$rel} on the server before deploying."
                    );
                } elseif (! @mkdir($sharedPath, 0775, true) && ! is_dir($sharedPath)) {
                    // mkdir(recursive) creates any missing parent directories itself.
                    throw new StorageException("Failed to create shared path: {$rel}");
                }
            }

            // Replace whatever is in the release with a symlink to the shared copy.
            if (file_exists($releasePath) || is_link($releasePath)) {
                $this->deleteRecursive($releasePath);
            }
            $this->ensureParent($releasePath);

            if (! @symlink($sharedPath, $releasePath)) {
                throw new StorageException("Failed to symlink shared path (symlink required): {$rel}");
            }
        }
    }

    public function pruneReleases(string $projectBasePath, int $keep): array
    {
        $releasesDir = $this->jailedLeaf($this->trailingChild($projectBasePath, 'releases'));
        if (! is_dir($releasesDir)) {
            return [];
        }

        $current = $this->currentTarget($projectBasePath);
        $currentReal = $current !== null ? (realpath($current) ?: $current) : null;

        $dirs = [];
        foreach (scandir($releasesDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $releasesDir.'/'.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $dirs[$entry] = filemtime($path) ?: 0;
            }
        }

        arsort($dirs); // newest first
        $ids = array_keys($dirs);
        $keepSet = array_slice($ids, 0, max(0, $keep));

        $removed = [];
        foreach ($ids as $id) {
            if (in_array($id, $keepSet, true)) {
                continue;
            }
            $path = $releasesDir.'/'.$id;
            if ($currentReal !== null && (realpath($path) ?: $path) === $currentReal) {
                continue; // never delete the active release
            }
            $this->deleteRecursive($path);
            $removed[] = $id;
        }

        return $removed;
    }

    public function acquireLock(string $projectBasePath): bool
    {
        $lock = $this->jailedLeaf($this->trailingChild($projectBasePath, 'deploy.lock'));

        if (file_exists($lock)) {
            $age = time() - (filemtime($lock) ?: 0);
            if ($age <= $this->lockTtl) {
                return false; // held and fresh
            }
            @unlink($lock); // stale — steal it
        }

        $handle = @fopen($lock, 'x'); // O_EXCL: fails if it exists (race-safe)
        if ($handle === false) {
            return false;
        }

        fwrite($handle, (string) json_encode(['pid' => getmypid(), 'at' => time()]));
        fclose($handle);

        return true;
    }

    public function releaseLock(string $projectBasePath): void
    {
        $lock = $this->jailedLeaf($this->trailingChild($projectBasePath, 'deploy.lock'));
        if (file_exists($lock)) {
            @unlink($lock);
        }
    }

    public function currentTarget(string $projectBasePath): ?string
    {
        $current = $this->jailedLeaf($this->trailingChild($projectBasePath, 'current'));

        if (is_link($current)) {
            $target = readlink($current);
            if ($target === false) {
                return null;
            }
            if (! str_starts_with($target, '/')) {
                $target = dirname($current).'/'.$target;
            }

            return realpath($target) ?: $target;
        }

        if (is_dir($current)) {
            return realpath($current) ?: $current; // copy-swap fallback mode
        }

        return null;
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    private function safeSlug(string $slug): string
    {
        if ($slug === '' || str_contains($slug, '..') || ! preg_match('/^[A-Za-z0-9._-]+$/', $slug)) {
            throw new StorageException("Invalid project slug: {$slug}");
        }

        return $slug;
    }

    private function trailingChild(string $base, string $child): string
    {
        return rtrim($base, '/').'/'.$child;
    }

    /**
     * Normalize a shared-paths entry to [relativePath, type]. Accepts a bare string
     * (legacy — a directory) or a {path, type} pair; unknown types fall back to 'dir'.
     *
     * @param  mixed  $entry
     * @return array{0: string, 1: string}
     */
    private function sharedEntry($entry): array
    {
        if (is_string($entry)) {
            return [$entry, 'dir'];
        }

        if (is_array($entry)) {
            $rel = is_string($entry['path'] ?? null) ? $entry['path'] : '';
            $type = ($entry['type'] ?? 'dir') === 'file' ? 'file' : 'dir';

            return [$rel, $type];
        }

        return ['', 'dir'];
    }

    /**
     * Validate that a path's PARENT is inside the jail and return the (unresolved) leaf path,
     * for operating on symlinks/lock files that live directly under a jailed directory.
     */
    private function jailedLeaf(string $path): string
    {
        $parent = $this->jail->assertInside(dirname($path));
        $name = basename($path);
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, "\0")) {
            throw new StorageException("Invalid leaf name: {$path}");
        }

        return $parent.'/'.$name;
    }

    private function ensureParent(string $path): void
    {
        $parent = dirname($path);
        if (! is_dir($parent) && ! @mkdir($parent, 0775, true) && ! is_dir($parent)) {
            throw new StorageException("Failed to create directory: {$parent}");
        }
    }

    private function trySymlinkSwap(string $target, string $link): bool
    {
        if (! function_exists('symlink')) {
            return false;
        }

        $tmp = $link.'.tmp-'.bin2hex(random_bytes(4));
        if (! @symlink($target, $tmp)) {
            @unlink($tmp);

            return false;
        }
        if (! @rename($tmp, $link)) { // atomic replace of the existing symlink
            @unlink($tmp);

            return false;
        }

        return true;
    }

    private function copySwap(string $target, string $link): void
    {
        $staged = $link.'.new-'.bin2hex(random_bytes(4));
        $this->copyDir($target, $staged);

        $backup = null;
        if (file_exists($link) || is_link($link)) {
            $backup = $link.'.old-'.bin2hex(random_bytes(4));
            if (! @rename($link, $backup)) {
                $this->deleteRecursive($staged);
                throw new StorageException("activate: could not move current aside: {$link}");
            }
        }

        if (! @rename($staged, $link)) {
            if ($backup !== null) {
                @rename($backup, $link); // restore
            }
            $this->deleteRecursive($staged);
            throw new StorageException("activate: could not move new release into place: {$link}");
        }

        if ($backup !== null) {
            $this->deleteRecursive($backup);
        }
    }

    private function copyDir(string $src, string $dst): void
    {
        if (! is_dir($dst) && ! @mkdir($dst, 0775, true) && ! is_dir($dst)) {
            throw new StorageException("copy: failed to create {$dst}");
        }

        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $src.'/'.$entry;
            $to = $dst.'/'.$entry;

            if (is_link($from)) {
                @symlink((string) readlink($from), $to);
            } elseif (is_dir($from)) {
                $this->copyDir($from, $to);
            } elseif (! @copy($from, $to)) {
                throw new StorageException("copy: failed to copy {$from}");
            }
        }
    }

    /** Recursively delete a file/dir. Symlinks are unlinked, never followed. */
    private function deleteRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteRecursive($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
