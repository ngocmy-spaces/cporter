<?php

namespace App\Domain\Deploy;

use App\Enums\DeploymentStatus;
use App\Jobs\DeployProjectJob;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

/**
 * Per-project FIFO deploy dispatcher (docs/SPEC.md §6, §10).
 *
 * A deploy request only persists a `Deployment{queued}` — the queued rows ARE the per-project
 * backlog. This service decides when the next one may start: a project runs at most one deploy at
 * a time (`running`/`hooks_pending`), and its queued deploys start oldest-first (FIFO). Different
 * projects are independent, so under multiple `queue:work` workers they run in parallel; under the
 * single cPanel worker they interleave cooperatively.
 *
 * The claim is made atomic with a short per-project cache lock (the same DB-backed primitive as
 * `cporter:work`) so the precheck → select-oldest → transition can't race two claimers into both
 * starting a deploy for the same project. The conditional UPDATE (`WHERE status='queued'`) is a
 * correctness backstop on top.
 */
class DeployDispatcher
{
    /**
     * If the project has no active deploy, atomically claim its oldest queued deployment
     * (`queued`→`running`) and dispatch it. Returns the claimed deployment, or null when the
     * project is busy / has no backlog / lost the claim race.
     */
    public function claimNextFor(Project $project): ?Deployment
    {
        $claimed = Cache::lock("cporter:claim:{$project->id}", 10)->get(function () use ($project): ?Deployment {
            if ($project->activeDeployment() !== null) {
                return null; // a deploy is already holding (or about to hold) the lock
            }

            $next = $project->deployments()
                ->where('status', DeploymentStatus::Queued->value)
                ->orderBy('id') // FIFO
                ->first();

            if ($next === null) {
                return null;
            }

            // Compare-and-set: only the claimer whose UPDATE still sees `queued` wins.
            $won = Deployment::query()
                ->where('id', $next->id)
                ->where('status', DeploymentStatus::Queued->value)
                ->update([
                    'status' => DeploymentStatus::Running->value,
                    'started_at' => now(),
                ]);

            if ($won !== 1) {
                return null;
            }

            return $next->refresh();
        });

        if (! $claimed instanceof Deployment) {
            return null; // lock not acquired, or nothing to claim
        }

        DeployProjectJob::dispatch($claimed);

        return $claimed;
    }

    /**
     * Drain the backlog: for every project that has queued deploys, try to start the next one.
     * Called at the tail of the cron worker (run-jobs) and housekeeper so freed projects advance.
     *
     * @return int number of deploys started this pass
     */
    public function dispatchPending(): int
    {
        $projectIds = Deployment::query()
            ->where('status', DeploymentStatus::Queued->value)
            ->distinct()
            ->pluck('project_id');

        $started = 0;
        foreach ($projectIds as $projectId) {
            $project = Project::find($projectId);
            if ($project !== null && $this->claimNextFor($project) !== null) {
                $started++;
            }
        }

        return $started;
    }
}
