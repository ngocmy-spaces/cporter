<?php

namespace App\Adapters\Command;

use App\Domain\Command\CommandResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs commands via Symfony Process (proc_open). Works in the cron-worker's shell context
 * (docs/SPEC.md §9). If proc_open is unavailable, isAvailable() returns false and the deploy
 * engine surfaces hooks as "run manually" instead.
 *
 * The child process runs with cPorter's own environment stripped out — see {@see self::isolation()}.
 */
class ProcessCommandRunner implements CommandRunner
{
    public function isAvailable(): bool
    {
        return function_exists('proc_open');
    }

    public function run(string $command, string $workingDir, array $env = [], ?int $timeout = 300): CommandResult
    {
        // Caller-supplied values win over the isolation map (`+` keeps the left operand's keys).
        $process = Process::fromShellCommandline($command, $workingDir, $env + $this->isolation(), null, $timeout);

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

    /**
     * Every inherited environment variable that is not allow-listed, mapped to `false` — Symfony's
     * way of removing a variable from the child (Process::start() skips `false` entries when it
     * builds the env pairs). Allow-list, not deny-list: a deny-list silently misses new keys.
     *
     * Why this matters: a hook runs INSIDE the target app, which must resolve its config from its
     * own `.env`. phpdotenv is immutable ("Don't overwrite existing environment variables"), so any
     * variable cPorter's `.env` pushed into this process — Laravel's putenv adapter is on by default
     * — would win over the target's file. A leaked `DB_DATABASE` once made a target app's
     * `artisan migrate` hook run its migrations against cPorter's own database (docs/SPEC.md §23).
     * Symfony always merges the parent environment into the child and 7.x has no inheritance switch,
     * so isolating means naming each variable and unsetting it.
     *
     * @return array<string, false>
     */
    private function isolation(): array
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('cporter.hooks.env_passthrough', []);

        $strip = [];
        foreach (array_keys(array_merge(getenv(), $_ENV, $_SERVER)) as $name) {
            $name = (string) $name;
            if (! $this->passesThrough($name, $allowed)) {
                $strip[$name] = false;
            }
        }

        return $strip;
    }

    /**
     * @param  list<string>  $allowed  exact variable names, or `PREFIX_*` prefix patterns
     */
    private function passesThrough(string $name, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            $pattern = (string) $pattern;

            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($name, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif ($name === $pattern) {
                return true;
            }
        }

        return false;
    }
}
