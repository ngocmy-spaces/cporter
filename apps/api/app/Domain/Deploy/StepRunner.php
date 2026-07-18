<?php

namespace App\Domain\Deploy;

use App\Models\Deployment;
use Throwable;

/**
 * Records pipeline steps onto a Deployment as they run, persisting after each step so a
 * polling client sees live progress (docs/SPEC.md §6).
 */
class StepRunner
{
    /** @var list<array<string, mixed>> */
    private array $steps = [];

    public function __construct(private readonly Deployment $deployment) {}

    /** Run a step; record success/failure (with timing) and re-throw on error. */
    public function run(string $name, callable $fn): void
    {
        $start = microtime(true);
        try {
            $fn();
        } catch (Throwable $e) {
            $this->push($name, 'failed', $start, $e->getMessage());
            throw $e;
        }
        $this->push($name, 'success', $start);
    }

    /** Record a step outcome that was evaluated elsewhere (e.g. a boolean health check). */
    public function record(string $name, bool $ok, ?string $error = null): void
    {
        $this->push($name, $ok ? 'success' : 'failed', microtime(true), $ok ? null : $error);
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return $this->steps;
    }

    private function push(string $name, string $status, float $start, ?string $error = null): void
    {
        $step = [
            'name' => $name,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        if ($error !== null) {
            $step['error'] = $error;
        }

        $this->steps[] = $step;
        $this->deployment->forceFill(['steps' => $this->steps])->save();
    }
}
