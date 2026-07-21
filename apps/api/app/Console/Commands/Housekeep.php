<?php

namespace App\Console\Commands;

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Deploy\ArtifactPruner;
use App\Domain\Deploy\ReleasePruner;
use App\Domain\System\ArtifactStorageHeartbeat;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Console\Command;
use Throwable;

/**
 * Periodic cleanup (docs/SPEC.md §10): fail deployments stuck in running/hooks_pending past
 * the timeout and release their (now-stale) project locks, then reclaim redundant artifact
 * .zip files project-wide. Run on a schedule by the cron.
 */
class Housekeep extends Command
{
    protected $signature = 'cporter:housekeep';

    protected $description = 'Fail timed-out deployments, release stale locks, and reclaim artifact archives';

    public function handle(StorageAdapter $storage, ArtifactPruner $artifacts, ReleasePruner $releases, ArtifactStorageHeartbeat $heartbeat): int
    {
        $cutoff = now()->subSeconds((int) config('cporter.deployment_timeout', 1800));

        $stuck = Deployment::query()
            ->whereIn('status', [DeploymentStatus::Running->value, DeploymentStatus::HooksPending->value])
            ->where('started_at', '<', $cutoff)
            ->with(['project', 'release'])
            ->get();

        foreach ($stuck as $deployment) {
            $deployment->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();
            $deployment->release?->forceFill(['state' => ReleaseState::Failed])->save();

            if ($deployment->project !== null) {
                try {
                    $storage->releaseLock($deployment->project->base_path);
                } catch (Throwable) {
                    // base_path may be misconfigured / outside jail — ignore.
                }
            }
        }

        // Reclaim redundant artifact zips across every project (including soft-deleted ones, whose
        // backlog would otherwise never be cleaned). ArtifactPruner protects the live release and
        // any in-flight deploy's artifact, so a zip mid-deploy is never touched.
        $removed = 0;
        $freed = 0;
        $swept = 0;
        foreach (Project::withTrashed()->get() as $project) {
            try {
                // Reconcile release rows whose dirs are gone (self-heal historical data), then
                // reclaim redundant artifact zips.
                $releases->reconcile($project);
                $result = $artifacts->prune($project);
                $removed += $result['removed'];
                $freed += $result['freed'];
                $swept++;
            } catch (Throwable) {
                // Never let one project's storage error abort the whole sweep.
            }
        }

        // Record a heartbeat so the System status can show the artifact store size + backlog and
        // flag a stalled/disabled cleanup — the reclaim itself surfaces nowhere else.
        $heartbeat->record([
            'projects_swept' => $swept,
            'reclaimed_count' => $removed,
            'freed_bytes' => $freed,
            'store_bytes' => $storage->artifactStoreBytes(),
            'unpruned_count' => Artifact::query()->whereNotNull('storage_path')->count(),
        ]);

        $this->info("cporter:housekeep — failed {$stuck->count()} stuck deployment(s); reclaimed {$removed} artifact archive(s), {$freed} byte(s).");

        return self::SUCCESS;
    }
}
