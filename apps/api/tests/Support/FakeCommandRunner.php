<?php

namespace Tests\Support;

use App\Adapters\Command\CommandRunner;
use App\Domain\Command\CommandResult;

/**
 * Test double for CommandRunner — records commands instead of shelling out.
 */
class FakeCommandRunner implements CommandRunner
{
    /** @var list<array{command: string, cwd: string}> */
    public array $ran = [];

    public bool $available = true;

    /** @var array<string, int> command-substring => exit code (for simulating failures) */
    public array $failOn = [];

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function run(string $command, string $workingDir, array $env = [], ?int $timeout = 300): CommandResult
    {
        $this->ran[] = ['command' => $command, 'cwd' => $workingDir];

        foreach ($this->failOn as $needle => $code) {
            if (str_contains($command, $needle)) {
                return new CommandResult($code, "failed: {$command}");
            }
        }

        return new CommandResult(0, "ok: {$command}");
    }
}
