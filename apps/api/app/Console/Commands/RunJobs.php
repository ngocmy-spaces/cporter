<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * cron-worker entrypoint (docs/SPEC.md §9, §10).
 *
 * Invoked by a standing cPanel cron. Runs in cron's shell context, so it can execute
 * target-app shell commands that web PHP cannot. SKELETON — implemented in Phase 2 (T2.x).
 */
class RunJobs extends Command
{
    protected $signature = 'cporter:run-jobs {--max=20 : Max jobs to process this tick}';

    protected $description = 'Execute queued shell jobs (deploy hooks: migrate/cache/queue) in cron shell context';

    public function handle(): int
    {
        // TODO (Phase 2): fetch pending jobs, execute in shell, record exit code/output,
        // re-run health check, auto-rollback on failure.
        $this->info('cporter:run-jobs — nothing to do (stub).');

        return self::SUCCESS;
    }
}
