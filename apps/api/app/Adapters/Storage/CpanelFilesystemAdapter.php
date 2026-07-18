<?php

namespace App\Adapters\Storage;

use App\Domain\Storage\PathJail;
use App\Domain\Storage\PathOutsideJailException;
use App\Domain\Storage\StorageException;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * cPanel filesystem adapter — native PHP only (no shell): mkdir/rename/unlink/scandir,
 * ZipArchive, symlink (docs/SPEC.md §2.1, §11). All managed-project paths are confined
 * by {@see PathJail}; cPorter's own artifact storage is internal and not jailed.
 *
 * T1.2 implements putArtifact() + extractZip(). Release/symlink/lock methods land in T1.3.
 */
class CpanelFilesystemAdapter implements StorageAdapter
{
    public function __construct(
        private readonly PathJail $jail,
        private readonly int $maxFiles = 50000,
        private readonly int $maxBytes = 268435456,          // 256 MB
        private readonly int $maxUncompressedBytes = 1073741824, // 1 GB
    ) {}

    /**
     * Move an uploaded artifact into cPorter's internal artifact storage; returns the
     * stored absolute path. (Destination is cPorter-owned, so it is not jail-checked;
     * the project slug is sanitized to prevent directory escape.)
     */
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
            // rename() fails across filesystems — fall back to copy + unlink.
            if (! @copy($sourcePath, $dest)) {
                throw new StorageException("Failed to store artifact at: {$dest}");
            }
            @unlink($sourcePath);
        }

        return $dest;
    }

    /**
     * Extract a .zip into $destDir (must be inside the jail). Validates every entry against
     * Zip-Slip and enforces file-count / uncompressed-size caps BEFORE extracting.
     */
    public function extractZip(string $archivePath, string $destDir): void
    {
        if (! is_file($archivePath)) {
            throw new StorageException("Archive not found: {$archivePath}");
        }

        $dest = $this->jail->assertInside($destDir); // throws PathOutsideJailException if escaping

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

            // Confine every entry to $dest — reuse PathJail's traversal/symlink logic.
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

    private function safeSlug(string $slug): string
    {
        if ($slug === '' || str_contains($slug, '..') || ! preg_match('/^[A-Za-z0-9._-]+$/', $slug)) {
            throw new StorageException("Invalid project slug: {$slug}");
        }

        return $slug;
    }

    // ── T1.3: release activation / shared links / prune / lock ────────────────────

    public function activate(string $target, string $linkPath): void
    {
        throw new RuntimeException('Not implemented — see TASKS.md T1.3');
    }

    public function linkShared(string $releaseDir, string $sharedDir, array $sharedPaths): void
    {
        throw new RuntimeException('Not implemented — see TASKS.md T1.3');
    }

    public function pruneReleases(string $projectBasePath, int $keep): array
    {
        throw new RuntimeException('Not implemented — see TASKS.md T1.3');
    }

    public function acquireLock(string $projectBasePath): bool
    {
        throw new RuntimeException('Not implemented — see TASKS.md T1.3');
    }

    public function releaseLock(string $projectBasePath): void
    {
        throw new RuntimeException('Not implemented — see TASKS.md T1.3');
    }

    public function currentTarget(string $projectBasePath): ?string
    {
        throw new RuntimeException('Not implemented — see TASKS.md T1.3');
    }
}
