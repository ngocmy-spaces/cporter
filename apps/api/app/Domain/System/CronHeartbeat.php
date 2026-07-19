<?php

namespace App\Domain\System;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Cron "dead-man's switch" (docs/SPEC.md §10). Each cron tick records a heartbeat in the
 * settings store; the Admin UI reads status() to tell whether the cron is alive and which
 * cadence mode is in use — without any external monitoring service.
 *
 * Two independent keys keep the mode unambiguous:
 *   - TICK_KEY   written by `cporter:run-jobs` when driven directly by `schedule:run` (Mode A, 1-min cron)
 *   - WORKER_KEY written by `cporter:work` on every pass of its in-process loop (Mode B, 5-min cron)
 * When `cporter:work` invokes run-jobs internally it passes --no-heartbeat, so the tick key
 * only ever reflects a real Mode-A cron.
 */
class CronHeartbeat
{
    /** Mode A: schedule:run → cporter:run-jobs. */
    public const TICK_KEY = 'cron_tick_last_run';

    /** Mode B: cporter:work in-process loop. */
    public const WORKER_KEY = 'cron_worker_last_run';

    // A beat older than this is considered dead. Generous multiples of each cadence so a
    // slow pass or a skipped tick doesn't flap the status.
    private const WORKER_STALE_AFTER = 120;  // ~10× the default 12s pass cadence

    private const TICK_STALE_AFTER = 180;    // 3× a 1-minute tick

    /**
     * @param  array<string, mixed>  $extra
     */
    public function beat(string $key, array $extra = []): void
    {
        Setting::write($key, array_merge([
            'at' => now()->toIso8601String(),
            'host' => gethostname() ?: null,
            'pid' => getmypid() ?: null,
        ], $extra));
    }

    /**
     * @return array{state: string, mode: ?string, age_seconds: ?int, last_run_at: ?string, host: ?string, passes: ?int}
     */
    public function status(): array
    {
        $worker = Setting::read(self::WORKER_KEY);
        $tick = Setting::read(self::TICK_KEY);

        $workerAge = $this->ageOf($worker);
        $tickAge = $this->ageOf($tick);

        if ($workerAge !== null && $workerAge <= self::WORKER_STALE_AFTER) {
            return $this->report('B', 'healthy', $worker, $workerAge);
        }
        if ($tickAge !== null && $tickAge <= self::TICK_STALE_AFTER) {
            return $this->report('A', 'healthy', $tick, $tickAge);
        }

        // Neither is fresh — report the most recent known beat as "down", or "unknown" if the
        // cron has never run at all (fresh install / never configured).
        if ($workerAge !== null && ($tickAge === null || $workerAge <= $tickAge)) {
            return $this->report('B', 'down', $worker, $workerAge);
        }
        if ($tickAge !== null) {
            return $this->report('A', 'down', $tick, $tickAge);
        }

        return $this->report(null, 'unknown', null, null);
    }

    private function ageOf(mixed $beat): ?int
    {
        if (! is_array($beat) || ! isset($beat['at'])) {
            return null;
        }

        return max(0, now()->getTimestamp() - Carbon::parse($beat['at'])->getTimestamp());
    }

    /**
     * @param  array<string, mixed>|null  $last
     * @return array{state: string, mode: ?string, age_seconds: ?int, last_run_at: ?string, host: ?string, passes: ?int}
     */
    private function report(?string $mode, string $state, ?array $last, ?int $age): array
    {
        return [
            'state' => $state,          // healthy | down | unknown
            'mode' => $mode,            // 'A' | 'B' | null
            'age_seconds' => $age,
            'last_run_at' => $last['at'] ?? null,
            'host' => $last['host'] ?? null,
            'passes' => $last['passes'] ?? null,
        ];
    }
}
