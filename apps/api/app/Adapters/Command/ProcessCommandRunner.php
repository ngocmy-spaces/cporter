<?php

namespace App\Adapters\Command;

use App\Domain\Command\CommandResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs commands via Symfony Process (proc_open). Works in the cron-worker's shell context
 * (docs/SPEC.md §9). If proc_open is unavailable, isAvailable() returns false and the deploy
 * engine surfaces hooks as "run manually" instead.
 */
class ProcessCommandRunner implements CommandRunner
{
    public function isAvailable(): bool
    {
        return function_exists('proc_open');
    }

    public function run(string $command, string $workingDir, array $env = [], ?int $timeout = 300): CommandResult
    {
        $process = Process::fromShellCommandline($command, $workingDir, $env ?: null, null, $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CommandResult(124, trim($process->getOutput()."\n".$process->getErrorOutput()), true);
        }

        return new CommandResult(
            $process->getExitCode() ?? 1,
            trim($process->getOutput()."\n".$process->getErrorOutput()),
        );
    }
}
