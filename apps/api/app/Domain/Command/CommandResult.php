<?php

namespace App\Domain\Command;

/**
 * Result of running a shell command (docs/SPEC.md §9).
 */
class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly bool $timedOut = false,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
