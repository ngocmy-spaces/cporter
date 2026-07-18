<?php

namespace App\Console\Commands;

use App\Adapters\Storage\StorageAdapter;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Deployment;
use Illuminate\Console\Command;
use Throwable;

/**
 * Periodic cleanup (docs/SPEC.md §10): fail deployments stuck in running/hooks_pending past
 * the timeout and release their (now-stale) project locks. Run on a schedule by the cron.
 */
class Housekeep extends Command
{
    protected $signature = 'cporter:housekeep';

    protected $description = 'Fail timed-out deployments and release stale deploy locks';

    public function handle(StorageAdapter $storage): int
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

        $this->info("cporter:housekeep — failed {$stuck->count()} stuck deployment(s).");

        return self::SUCCESS;
    }
}
