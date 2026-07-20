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

    public function deleteArtifact(string $storagePath): int
    {
        // Confine deletions to cPorter's own artifact store — never let a stray/crafted path
        // reach the filesystem outside storage/app/artifacts/.
        $root = rtrim(storage_path('app/artifacts'), '/').'/';
        $real = realpath($storagePath);
        if ($real === false || ! str_starts_with($real, $root)) {
            return 0;
        }

        $freed = is_file($real) ? (filesize($real) ?: 0) : 0;
        @unlink($real);

        return $freed;
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

    public function writeSharedFile(string $sharedDir, string $relativePath, string $content, string $managedMarker, bool $force = false): string
    {
        $shared = $this->jail->assertInside($sharedDir);
        if (! is_dir($shared) && ! @mkdir($shared, 0775, true) && ! is_dir($shared)) {
            throw new StorageException("Failed to create shared directory: {$shared}");
        }

        $target = $this->jailedSharedTarget($shared, $relativePath);

        // Ownership guard: never clobber a file we didn't write (e.g. a hand-created shared/.env)
        // unless the caller forces a takeover (docs/SPEC.md §9).
        if (! $force && (is_file($target) || is_link($target)) && ! $this->hasManagedMarker($target, $managedMarker)) {
            return 'skipped_unmanaged';
        }

        $this->ensureParent($target);

        $tmp = $target.'.tmp-'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw new StorageException('Failed to write shared file: '.basename($target));
        }
        if (! @rename($tmp, $target)) { // atomic replace
            @unlink($tmp);
            throw new StorageException('Failed to move shared file into place: '.basename($target));
        }

        return 'written';
    }

    public function sharedFileState(string $sharedDir, string $relativePath, string $managedMarker): array
    {
        $shared = $this->jail->assertInside($sharedDir);
        $target = $this->jailedSharedTarget($shared, $relativePath);

        if (! is_file($target) && ! is_link($target)) {
            return ['exists' => false, 'managed' => false];
        }

        return ['exists' => true, 'managed' => $this->hasManagedMarker($target, $managedMarker)];
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

    public function purgeProject(string $projectBasePath, string $level): int
    {
        if ($level === 'all') {
            // Whole project folder. assertInside resolves + jails the path before we recurse.
            $base = $this->jail->assertInside($projectBasePath);
            $freed = is_dir($base) ? $this->dirBytes($base) : 0;
            $this->deleteRecursive($base);

            return $freed;
        }

        // 'releases': drop the versioned release tree + the `current` symlink, keep shared/.
        $releasesDir = $this->jailedLeaf($this->trailingChild($projectBasePath, 'releases'));
        $current = $this->jailedLeaf($this->trailingChild($projectBasePath, 'current'));

        $freed = is_dir($releasesDir) ? $this->dirBytes($releasesDir) : 0;
        $this->deleteRecursive($releasesDir);
        if (is_link($current) || file_exists($current)) {
            $this->deleteRecursive($current);
        }

        return $freed;
    }

    public function diskStats(string $projectBasePath): array
    {
        $releasesDir = $this->jailedLeaf($this->trailingChild($projectBasePath, 'releases'));
        $sharedDir = $this->jailedLeaf($this->trailingChild($projectBasePath, 'shared'));

        $releases = is_dir($releasesDir) ? $this->dirBytes($releasesDir) : 0;

        $current = 0;
        $active = $this->currentTarget($projectBasePath); // resolves `current` -> release dir
        if ($active !== null && is_dir($active)) {
            $current += $this->dirBytes($active); // active release's own files (shared symlinks skipped)
        }
        if (is_dir($sharedDir)) {
            $current += $this->dirBytes($sharedDir); // real shared data, counted once
        }

        return ['current' => $current, 'releases' => $releases];
    }

    public function sharedPathSizes(string $projectBasePath, array $sharedPaths): array
    {
        $sharedDir = $this->jailedLeaf($this->trailingChild($projectBasePath, 'shared'));
        if (! is_dir($sharedDir)) {
            return [];
        }

        // Resolve each entry against a jail rooted at shared/ so a crafted `..` in a path can't
        // escape and size arbitrary files — mirrors linkShared()'s resolution.
        $shared = $this->jail->assertInside($sharedDir);
        $sharedJail = new PathJail([$shared]);

        $sizes = [];
        foreach ($sharedPaths as $entry) {
            [$rel, $type] = $this->sharedEntry($entry);
            if ($rel === '') {
                continue;
            }

            try {
                $sharedPath = $sharedJail->assertInside($shared.'/'.$rel);
            } catch (\Throwable) {
                continue; // entry escapes the shared jail (or is malformed) — skip, don't size the wrong thing
            }

            if ($type === 'file') {
                $sizes[$rel] = is_file($sharedPath) && ! is_link($sharedPath) ? (filesize($sharedPath) ?: 0) : 0;
            } else {
                $sizes[$rel] = is_dir($sharedPath) ? $this->dirBytes($sharedPath) : 0;
            }
        }

        return $sizes;
    }

    /** Recursively sum real file sizes under $dir, never following symlinks. */
    private function dirBytes(string $dir): int
    {
        $total = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_link($path)) {
                continue; // shared/ + current symlinks — skip to avoid double counting
            }
            if (is_dir($path)) {
                $total += $this->dirBytes($path);
            } elseif (is_file($path)) {
                $total += filesize($path) ?: 0;
            }
        }

        return $total;
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

    public function preflight(string $projectBasePath, array $sharedPaths = []): array
    {
        // Jail-validate the base once; a config/path mismatch is a hard, blocking error.
        try {
            $base = $this->jail->assertInside($projectBasePath);
        } catch (PathOutsideJailException $e) {
            return [
                'ok' => false,
                'base_path' => $projectBasePath,
                'checks' => [[
                    'key' => 'base_path',
                    'label' => 'Project base directory',
                    'status' => 'error',
                    'detail' => $e->getMessage(),
                ]],
            ];
        }

        $checks = [
            $this->ensureDirCheck('base_path', $base, 'Project base directory'),
            $this->ensureDirCheck('releases', rtrim($base, '/').'/releases', 'releases/ (versioned release directories)'),
            $this->ensureDirCheck('shared', rtrim($base, '/').'/shared', 'shared/ (data persisted across releases)'),
            $this->probeSymlink($base),
            $this->currentCheck($projectBasePath),
            $this->sharedFilesCheck(rtrim($base, '/').'/shared', $sharedPaths),
        ];

        $ok = array_filter($checks, fn (array $c) => $c['status'] === 'error') === [];

        return ['ok' => $ok, 'base_path' => $base, 'checks' => array_values($checks)];
    }

    /** Create $path if missing (reconcile), reporting created / ok / error. */
    private function ensureDirCheck(string $key, string $path, string $label): array
    {
        if (is_dir($path)) {
            return ['key' => $key, 'label' => $label, 'status' => 'ok', 'detail' => "Exists: {$path}"];
        }

        if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
            $reason = error_get_last()['message'] ?? 'unknown error';

            return ['key' => $key, 'label' => $label, 'status' => 'error', 'detail' => "Could not create {$path}: {$reason}"];
        }

        return ['key' => $key, 'label' => $label, 'status' => 'created', 'detail' => "Created: {$path}"];
    }

    /**
     * Probe symlink support with a throwaway link — this is the single biggest deploy-time
     * surprise, so we surface it up front (a host without symlinks falls back to copy-swap).
     */
    private function probeSymlink(string $base): array
    {
        $label = 'Symlink support';

        if (! function_exists('symlink')) {
            return ['key' => 'symlink', 'label' => $label, 'status' => 'warning', 'detail' => 'symlink() is disabled — deploys will use the slower, non-atomic copy-swap activation.'];
        }

        $probe = rtrim($base, '/').'/.cporter-symlink-probe-'.bin2hex(random_bytes(4));
        $ok = @symlink($base, $probe);
        if (is_link($probe) || file_exists($probe)) {
            @unlink($probe);
        }

        return $ok
            ? ['key' => 'symlink', 'label' => $label, 'status' => 'ok', 'detail' => 'Symlinks work — atomic release activation is enabled.']
            : ['key' => 'symlink', 'label' => $label, 'status' => 'warning', 'detail' => 'Could not create a symlink — deploys will use the slower, non-atomic copy-swap activation.'];
    }

    /** Inspect the `current` symlink without creating it (nothing to point at pre-deploy). */
    private function currentCheck(string $projectBasePath): array
    {
        $label = 'current symlink';
        $current = $this->jailedLeaf($this->trailingChild($projectBasePath, 'current'));

        if (is_link($current)) {
            $target = $this->currentTarget($projectBasePath);
            if ($target !== null && is_dir($target)) {
                return ['key' => 'current', 'label' => $label, 'status' => 'ok', 'detail' => 'Points to the active release: '.basename($target)];
            }

            return ['key' => 'current', 'label' => $label, 'status' => 'warning', 'detail' => 'Dangling symlink — points to a release that no longer exists. The next deploy repairs it.'];
        }

        if (is_dir($current)) {
            return ['key' => 'current', 'label' => $label, 'status' => 'ok', 'detail' => 'Present as a directory (copy-swap mode).'];
        }

        return ['key' => 'current', 'label' => $label, 'status' => 'pending', 'detail' => 'Not created yet — cPorter creates it on the first successful deploy. Do not create it by hand.'];
    }

    /**
     * Flag shared FILE entries that are absent from shared/. Directories are auto-created and
     * files may be seeded from the artifact, but a shared file with no artifact source (e.g.
     * shared/.env) must be created by the operator or the deploy fails at link_shared.
     */
    private function sharedFilesCheck(string $shared, array $sharedPaths): array
    {
        $label = 'Shared files';
        $missing = [];

        foreach ($sharedPaths as $entry) {
            [$rel, $type] = $this->sharedEntry($entry);
            if ($rel === '' || $type !== 'file') {
                continue;
            }
            $path = $shared.'/'.$rel;
            if (! file_exists($path) && ! is_link($path)) {
                $missing[] = 'shared/'.$rel;
            }
        }

        if ($missing === []) {
            return ['key' => 'shared_files', 'label' => $label, 'status' => 'ok', 'detail' => 'No shared files are missing.'];
        }

        return [
            'key' => 'shared_files',
            'label' => $label,
            'status' => 'warning',
            'detail' => 'Create these on the server before deploying (cPorter never fabricates secret/config files): '.implode(', ', $missing),
        ];
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

    /** Confine a relative path to the already-jailed shared dir and return the absolute target. */
    private function jailedSharedTarget(string $shared, string $relativePath): string
    {
        $rel = trim($relativePath, '/');
        if ($rel === '') {
            throw new StorageException('Shared file relative path is empty.');
        }

        return (new PathJail([$shared]))->assertInside($shared.'/'.$rel);
    }

    /** True if the file's first line starts with $marker (cPorter owns it). */
    private function hasManagedMarker(string $path, string $marker): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $firstLine = fgets($handle);
        fclose($handle);

        return is_string($firstLine) && str_starts_with(rtrim($firstLine, "\r\n"), $marker);
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
