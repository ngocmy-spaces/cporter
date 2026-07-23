<?php

namespace App\Console\Commands;

use App\Domain\Deploy\DeployDispatcher;
use App\Domain\Deploy\DeployEngine;
use App\Domain\System\CronHeartbeat;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Console\Command;

/**
 * cron-worker entrypoint (docs/SPEC.md §9, §10).
 *
 * Invoked by a standing cPanel cron. Runs in cron's shell context, so it can execute the
 * target-app hooks (migrate/cache/queue:restart) that web PHP cannot, then finalizes each
 * hooks_pending deployment (activate → health → prune / rollback).
 */
class RunJobs extends Command
{
    protected $signature = 'cporter:run-jobs
        {--max=10 : Max deployments to finalize this tick}
        {--no-heartbeat : Skip the Mode-A cron heartbeat (set when invoked from cporter:work)}';

    protected $description = 'Finalize hooks_pending deployments: run Laravel hooks, activate, health-check';

    public function handle(DeployEngine $engine, CronHeartbeat $heartbeat, DeployDispatcher $dispatcher): int
    {
        // Record the Mode-A cron heartbeat unless we're a nested pass inside cporter:work
        // (which records its own Mode-B heartbeat instead).
        if (! $this->option('no-heartbeat')) {
            $heartbeat->beat(CronHeartbeat::TICK_KEY);
        }

        $pending = Deployment::query()
            ->where('status', DeploymentStatus::HooksPending->value)
            ->orderBy('id')
            ->limit((int) $this->option('max'))
            ->get();

        foreach ($pending as $deployment) {
            $this->info("Finalizing deployment #{$deployment->id} (project {$deployment->project_id})…");
            $result = $engine->finalize($deployment);
            $this->line("  → {$result->status->value}");
        }

        // Drain the per-project FIFO deploy backlog: any project freed by a finalize above (or by a
        // finished no-hook deploy) now starts its next queued deploy (docs/SPEC.md §6, §10).
        $started = $dispatcher->dispatchPending();

        $this->info("cporter:run-jobs — processed {$pending->count()} deployment(s); started {$started} queued deploy(s).");

        return self::SUCCESS;
    }
}
