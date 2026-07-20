<?php

namespace App\Domain\Deploy;

use App\Adapters\Storage\StorageAdapter;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Support\Collection;

/**
 * Reclaims on-disk artifact .zip files (docs/SPEC.md §5, §6).
 *
 * The zip is consumed exactly once — extraction into the release directory. Rollback re-points
 * `current` at an already-extracted release dir and never re-extracts, so a zip is dead weight
 * the moment its deploy is stable. This prunes every project zip EXCEPT the ones still needed:
 *
 *   - the currently-active ("live") release's artifact,
 *   - any artifact whose release has an in-flight deployment (queued/running/hooks_pending) —
 *     that deploy is about to read, or is reading, the zip,
 *   - artifacts not yet attached to a release (a chunked upload mid-flight, between the Artifact
 *     row and its Release row being created).
 *
 * DB rows are ALWAYS kept — only the file is unlinked; storage_path is nulled and pruned_at
 * stamped, leaving size / sha256 / filename intact for the summary + charts.
 */
class ArtifactPruner
{
    public function __construct(private readonly StorageAdapter $storage) {}

    /**
     * @return array{removed: int, freed: int}
     */
    public function prune(Project $project): array
    {
        if (! (bool) config('cporter.artifact.prune_after_deploy', true)) {
            return ['removed' => 0, 'freed' => 0];
        }

        $protected = $this->protectedArtifactIds($project);

        // Only reclaim artifacts that (a) still have a file, (b) are not protected, and (c) are
        // attached to a release — an artifact with no release is a mid-upload we must not touch.
        $releasedArtifactIds = Release::query()
            ->where('project_id', $project->id)
            ->whereNotNull('artifact_id')
            ->pluck('artifact_id');

        $candidates = Artifact::query()
            ->where('project_id', $project->id)
            ->whereNotNull('storage_path')
            ->whereIn('id', $releasedArtifactIds)
            ->whereNotIn('id', $protected)
            ->get();

        $removed = 0;
        $freed = 0;

        foreach ($candidates as $artifact) {
            $freed += $this->storage->deleteArtifact((string) $artifact->storage_path);
            $artifact->forceFill([
                'storage_path' => null,
                'pruned_at' => now(),
            ])->save();
            $removed++;
        }

        return ['removed' => $removed, 'freed' => $freed];
    }

    /**
     * Artifact ids whose zip must be kept on disk.
     *
     * @return Collection<int, int>
     */
    private function protectedArtifactIds(Project $project): Collection
    {
        $live = Release::query()
            ->where('project_id', $project->id)
            ->where('state', ReleaseState::Active->value)
            ->whereNotNull('artifact_id')
            ->pluck('artifact_id');

        $inFlightReleaseIds = Deployment::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [
                DeploymentStatus::Queued->value,
                DeploymentStatus::Running->value,
                DeploymentStatus::HooksPending->value,
            ])
            ->pluck('release_id');

        $inFlight = Release::query()
            ->whereIn('id', $inFlightReleaseIds)
            ->whereNotNull('artifact_id')
            ->pluck('artifact_id');

        return $live->merge($inFlight)->unique()->values();
    }
}
