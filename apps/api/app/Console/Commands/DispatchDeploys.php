<?php

namespace App\Console\Commands;

use App\Domain\Deploy\DeployDispatcher;
use Illuminate\Console\Command;

/**
 * Drain the per-project FIFO deploy backlog: start the next queued deploy for every project that
 * is idle (docs/SPEC.md §6, §10). Normally this runs implicitly at the tail of `cporter:run-jobs`
 * and `cporter:housekeep`; this thin command exposes it for tests and manual ops. Not scheduled
 * separately.
 */
class DispatchDeploys extends Command
{
    protected $signature = 'cporter:dispatch-deploys';

    protected $description = 'Start the next queued deploy for each idle project (per-project FIFO)';

    public function handle(DeployDispatcher $dispatcher): int
    {
        $started = $dispatcher->dispatchPending();
        $this->info("cporter:dispatch-deploys — started {$started} queued deploy(s).");

        return self::SUCCESS;
    }
}
