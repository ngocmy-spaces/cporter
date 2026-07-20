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
    private array $steps;

    public function __construct(private readonly Deployment $deployment)
    {
        // Continue appending to any steps already recorded (e.g. staging steps before the
        // cron finalize resumes the pipeline).
        $this->steps = $deployment->steps ?? [];
    }

    /**
     * Run a step; record success/failure (with timing) and re-throw on error. The callable may
     * return a string to attach as the successful step's note (e.g. "Reclaimed 3 archives").
     */
    public function run(string $name, callable $fn): void
    {
        $start = microtime(true);
        try {
            $note = $fn();
        } catch (Throwable $e) {
            $this->push($name, 'failed', $start, $e->getMessage());
            throw $e;
        }
        $this->push($name, 'success', $start, null, is_string($note) ? $note : null);
    }

    /** Record a step outcome that was evaluated elsewhere (e.g. a boolean health check). */
    public function record(string $name, bool $ok, ?string $error = null): void
    {
        $this->push($name, $ok ? 'success' : 'failed', microtime(true), $ok ? null : $error);
    }

    /**
     * Record a non-fatal warning: the step neither succeeded cleanly nor failed the deploy — e.g.
     * write_env skipping an unmanaged shared/.env (docs/SPEC.md §9). The pipeline continues.
     */
    public function warn(string $name, string $note): void
    {
        $this->push($name, 'warning', microtime(true), null, $note);
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return $this->steps;
    }

    private function push(string $name, string $status, float $start, ?string $error = null, ?string $note = null): void
    {
        $step = [
            'name' => $name,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        if ($error !== null) {
            $step['error'] = $error;
        }
        if ($note !== null) {
            $step['note'] = $note;
        }

        $this->steps[] = $step;
        $this->deployment->forceFill(['steps' => $this->steps])->save();
    }
}
