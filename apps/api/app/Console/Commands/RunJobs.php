<?php

namespace App\Console\Commands;

use App\Domain\Deploy\DeployEngine;
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
    protected $signature = 'cporter:run-jobs {--max=10 : Max deployments to finalize this tick}';

    protected $description = 'Finalize hooks_pending deployments: run Laravel hooks, activate, health-check';

    public function handle(DeployEngine $engine): int
    {
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

        $this->info("cporter:run-jobs — processed {$pending->count()} deployment(s).");

        return self::SUCCESS;
    }
}
