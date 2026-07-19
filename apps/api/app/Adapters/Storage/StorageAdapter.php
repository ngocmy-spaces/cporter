<?php

namespace App\Adapters\Storage;

/**
 * Storage abstraction for release/filesystem operations (docs/SPEC.md §11).
 *
 * All paths MUST be validated against the configured jail (allowed base paths)
 * before any operation — open_basedir is OFF on the target host, so the jail is
 * enforced here in code (docs/SPEC.md §2.1, §12).
 */
interface StorageAdapter
{
    /** Persist an uploaded artifact; returns the stored absolute path. */
    public function putArtifact(string $sourcePath, string $projectSlug): string;

    /** Extract a .zip into $destDir (Zip-Slip safe). */
    public function extractZip(string $archivePath, string $destDir): void;

    /** Atomically point $linkPath at $target (symlink tmp + rename, or rename-swap fallback). */
    public function activate(string $target, string $linkPath): void;

    /**
     * Symlink shared paths from $sharedDir into $releaseDir.
     *
     * Each entry is either a bare relative path (legacy — treated as a directory) or
     * a {path, type} pair where type is 'file' or 'dir'. The type disambiguates how a
     * shared entry absent from both the release and shared/ is seeded.
     *
     * @param  list<string|array{path: string, type: string}>  $sharedPaths
     *                                                                       e.g. [['path' => '.env', 'type' => 'file'], ['path' => 'storage', 'type' => 'dir']]
     */
    public function linkShared(string $releaseDir, string $sharedDir, array $sharedPaths): void;

    /**
     * Delete releases beyond the newest $keep.
     *
     * @return list<string> removed release ids
     */
    public function pruneReleases(string $projectBasePath, int $keep): array;

    /**
     * Reclaim disk for a project being deleted (docs/SPEC.md §5).
     *
     * @param  'releases'|'all'  $level
     *                                   'releases' → delete releases/ and the `current` symlink, keep shared/ (user data such as .env, uploads)
     *                                   'all'      → delete the entire project base_path folder
     * @return int bytes freed (best-effort, measured before deletion)
     */
    public function purgeProject(string $projectBasePath, string $level): int;

    /**
     * Compute the project's on-disk footprint in bytes. Symlinks are never followed, so
     * shared content (symlinked into each release) is counted once, under `current`.
     *
     * @return array{current: int, releases: int}
     *                                            current  = the live release (via `current`) plus shared/ — what the site occupies now
     *                                            releases = every retained release directory under releases/ (rollback history)
     */
    public function diskStats(string $projectBasePath): array;

    /** Acquire the per-project deploy lock (atomic O_EXCL). Returns false if already locked. */
    public function acquireLock(string $projectBasePath): bool;

    /** Release the per-project deploy lock. */
    public function releaseLock(string $projectBasePath): void;

    /** Resolve what `current` points at, or null if none. */
    public function currentTarget(string $projectBasePath): ?string;
}
