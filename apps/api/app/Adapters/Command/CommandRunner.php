<?php

namespace App\Adapters\Command;

/**
 * Command execution abstraction (docs/SPEC.md §9).
 *
 * The target host has NO usable exec/proc_open in web PHP, so target-app shell
 * commands (e.g. `php artisan migrate`) are enqueued and executed later by the
 * cron-worker running in cron's shell context. Implementations: CronWorkerRunner
 * (primary), ManualRunner (fallback), and optionally SshRunner.
 */
interface CommandRunner
{
    /**
     * Enqueue a shell command to run in $workingDir; returns a job id to poll.
     *
     * @param array<string, string> $env
     */
    public function enqueue(string $command, string $workingDir, array $env = []): string;

    /** Driver name: 'cron-worker' | 'manual' | 'ssh' | 'proc_open'. */
    public function driver(): string;
}
