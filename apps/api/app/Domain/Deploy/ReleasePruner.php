<?php

namespace App\Domain\Deploy;

use App\Adapters\Storage\StorageAdapter;
use App\Enums\ReleaseState;
use App\Models\Project;
use App\Models\Release;

/**
 * Applies release retention (keep_releases) and keeps the DB in step with the disk.
 *
 * The storage adapter deletes the oldest release DIRECTORIES beyond the newest $keep; this
 * wrapper then marks the matching Release rows Pruned so the Releases list only ever shows
 * releases that still exist and can be re-activated (docs/SPEC.md §6, §8). Rows are kept for
 * history — full detail stays available in the Deployments view.
 *
 * Shared by the deploy pipeline (every successful deploy) and the keep_releases edit path.
 */
class ReleasePruner
{
    public function __construct(private readonly StorageAdapter $storage) {}

    /**
     * @return list<string> removed release directory names
     */
    public function prune(Project $project, int $keep): array
    {
        $removed = $this->storage->pruneReleases($project->base_path, $keep);

        if ($removed !== []) {
            $this->markPruned($project, $removed);
        }

        return $removed;
    }

    /**
     * Map removed directory names (the trailing segment of release.path, e.g. "20260720_001")
     * back to Release rows and mark them Pruned. A Failed release keeps its state (already
     * excluded from the Releases list); everything else becomes Pruned.
     *
     * @param  list<string>  $removedDirs
     */
    private function markPruned(Project $project, array $removedDirs): void
    {
        $releases = Release::query()
            ->where('project_id', $project->id)
            ->whereNotIn('state', [ReleaseState::Failed->value, ReleaseState::Pruned->value])
            ->get();

        foreach ($releases as $release) {
            if (in_array(basename((string) $release->path), $removedDirs, true)) {
                $release->forceFill(['state' => ReleaseState::Pruned])->save();
            }
        }
    }
}
