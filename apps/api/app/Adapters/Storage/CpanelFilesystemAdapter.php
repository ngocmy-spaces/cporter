<?php

namespace App\Adapters\Storage;

use RuntimeException;

/**
 * cPanel filesystem adapter — native PHP only (no shell): mkdir/rename/unlink/scandir,
 * ZipArchive, symlink (docs/SPEC.md §2.1, §11).
 *
 * SKELETON — implemented per-task in Phase 1 (see TASKS.md T1.x).
 */
class CpanelFilesystemAdapter implements StorageAdapter
{
    /** @param list<string> $allowedBasePaths Jail: every path must resolve within one of these. */
    public function __construct(
        private readonly array $allowedBasePaths = [],
    ) {}

    public function putArtifact(string $sourcePath, string $projectSlug): string
    {
        throw new RuntimeException('Not implemented — see TASKS.md T1.2');
    }

    public function extractZip(string $archivePath, string $destDir): void
    {
        throw new RuntimeException('Not implemented — see TASKS.md T1.2');
    }

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
