<?php

namespace App\Jobs;

use App\Domain\Deploy\DeployEngine;
use App\Models\Deployment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the deploy pipeline off the request (docs/SPEC.md §6). With the database queue +
 * cron worker this is async (202 + poll); with the sync driver (dev/tests) it runs inline.
 */
class DeployProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly Deployment $deployment) {}

    public function handle(DeployEngine $engine): void
    {
        $engine->deploy($this->deployment);
    }
}
