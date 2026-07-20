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
     * Self-heal release rows whose directory no longer exists on disk — e.g. releases pruned
     * before this bookkeeping existed, or removed out-of-band. They are marked `pruned` so the
     * releases list only ever offers activatable targets (a dangling row would otherwise show a
     * dead "Activate" button). The live (`active`) release is left alone even if dangling, so a
     * broken deploy stays visible rather than silently vanishing.
     *
     * @return int rows reconciled
     */
    public function reconcile(Project $project): int
    {
        $releases = Release::query()
            ->where('project_id', $project->id)
            ->where('state', ReleaseState::Superseded->value)
            ->get();

        $count = 0;
        foreach ($releases as $release) {
            if (! is_dir((string) $release->path)) {
                $release->forceFill(['state' => ReleaseState::Pruned])->save();
                $count++;
            }
        }

        return $count;
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
