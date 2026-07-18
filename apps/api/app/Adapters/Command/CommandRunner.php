<?php

namespace App\Adapters\Command;

use App\Domain\Command\CommandResult;

/**
 * Executes a target-app shell command (docs/SPEC.md §9).
 *
 * The target host has NO usable exec/proc_open in web PHP, so this is only ever called
 * from the cron-worker (`cporter:run-jobs`), which runs in cron's shell context. Web-request
 * code never calls run() — it stages the release and defers hooks to the cron finalize.
 */
interface CommandRunner
{
    /** Whether shell execution is possible in the current context (proc_open available). */
    public function isAvailable(): bool;

    /**
     * @param  array<string, string>  $env
     */
    public function run(string $command, string $workingDir, array $env = [], ?int $timeout = 300): CommandResult;
}
