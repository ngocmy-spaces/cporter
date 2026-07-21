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

    /**
     * Delete a stored artifact .zip (cPorter's own internal storage, not a jailed project path)
     * once it is no longer needed — the deploy is stable and rollback never re-extracts it.
     *
     * @return int bytes freed (0 if the file was already gone), best-effort.
     */
    public function deleteArtifact(string $storagePath): int;

    /** Total on-disk size (bytes) of cPorter's internal artifact store (storage/app/artifacts). */
    public function artifactStoreBytes(): int;

    /**
     * Reclaim orphaned entries in the artifact store that the DB-driven pruner can't see: `.zip`
     * files no Artifact row references (e.g. rows pruned earlier but the file was left behind, or
     * a deleted project's leftovers) and stale chunked-upload session dirs. Only entries OLDER
     * than $minAgeSeconds are touched, so an in-flight upload/deploy is never affected.
     *
     * @param  list<string>  $referenced  absolute storage paths still referenced by an Artifact row (kept)
     * @return array{removed: int, freed: int}
     */
    public function pruneOrphanArtifacts(array $referenced, int $minAgeSeconds): array;

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
     * Write a cPorter-managed file into $sharedDir (e.g. shared/.env from managed env vars,
     * docs/SPEC.md §9). $content's first line MUST be $managedMarker, the ownership sentinel.
     *
     * Ownership rule (never clobber a hand-created file):
     *   - target absent, or present with a first line starting with $managedMarker → write it.
     *   - present WITHOUT the marker and ! $force → skip (leave the file untouched).
     *   - $force → overwrite regardless (operator taking the file over from the UI).
     *
     * @return 'written'|'skipped_unmanaged'
     */
    public function writeSharedFile(string $sharedDir, string $relativePath, string $content, string $managedMarker, bool $force = false): string;

    /**
     * Inspect a shared file's presence and cPorter-ownership without modifying it, so the UI can
     * decide whether to show the conflict warning / force-adopt action.
     *
     * @return array{exists: bool, managed: bool}
     *                                            managed = the file exists and its first line starts with $managedMarker
     */
    public function sharedFileState(string $sharedDir, string $relativePath, string $managedMarker): array;

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

    /**
     * Per-shared-path on-disk sizes in bytes, keyed by the entry's relative path. Each entry's
     * real data lives at shared/<path>; a directory is walked recursively (symlinks skipped),
     * a file is stat'd, and a not-yet-created entry reports 0.
     *
     * @param  array<int, mixed>  $sharedPaths  the project's shared_paths (each a {path, type} pair or legacy string)
     * @return array<string, int> relative path => bytes
     */
    public function sharedPathSizes(string $projectBasePath, array $sharedPaths): array;

    /** Acquire the per-project deploy lock (atomic O_EXCL). Returns false if already locked. */
    public function acquireLock(string $projectBasePath): bool;

    /** Release the per-project deploy lock. */
    public function releaseLock(string $projectBasePath): void;

    /** Resolve what `current` points at, or null if none. */
    public function currentTarget(string $projectBasePath): ?string;

    /**
     * Idempotently ensure a project's on-disk scaffold and report on host readiness, so
     * setup errors surface at project-setup time rather than mid-deploy (docs/SPEC.md §11).
     *
     * Creates any missing `base_path`, `releases/` and `shared/` directories (reconcile — never
     * overwrites), probes symlink support, inspects the `current` symlink, and flags shared
     * FILE entries that must be created by hand. It never creates `current` (there is no release
     * to point at yet) and never touches the domain's Document Root (a cPanel vhost concern).
     *
     * Lenient: filesystem failures are reported as `error` checks, not thrown.
     *
     * @param  list<string|array{path: string, type: string}>  $sharedPaths
     * @return array{ok: bool, base_path: string, checks: list<array{key: string, label: string, status: string, detail: string}>}
     *                                                                                                                             status ∈ ok | created | pending | warning | error
     */
    public function preflight(string $projectBasePath, array $sharedPaths = []): array;
}
