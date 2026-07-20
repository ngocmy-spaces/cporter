<?php

namespace App\Console\Commands;

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Deploy\ArtifactPruner;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
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

    public function handle(StorageAdapter $storage, ArtifactPruner $artifacts): int
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
        foreach (Project::withTrashed()->get() as $project) {
            try {
                $result = $artifacts->prune($project);
                $removed += $result['removed'];
                $freed += $result['freed'];
            } catch (Throwable) {
                // Never let one project's storage error abort the whole sweep.
            }
        }

        $this->info("cporter:housekeep — failed {$stuck->count()} stuck deployment(s); reclaimed {$removed} artifact archive(s), {$freed} byte(s).");

        return self::SUCCESS;
    }
}
