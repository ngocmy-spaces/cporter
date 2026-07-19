<?php

namespace App\Console\Commands;

use App\Domain\System\CronHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Internal-loop cron-worker (docs/SPEC.md §9, §10).
 *
 * cPanel caps cron cadence at 5 minutes on many plans, so a plain `everyMinute` scheduler
 * tick can leave a Laravel deploy waiting up to ~5 min to finalize. This command is invoked
 * ONCE per cron tick and then loops in-process for `--duration` seconds — each pass finalizes
 * hooks_pending deploys, drains the staging queue, and periodically housekeeps — sleeping
 * `--sleep` seconds between passes. It exits before the next cron fires, so effective latency
 * drops from ~5 min to ~`--sleep` seconds while still running in cron's shell context (where
 * proc_open works and target-app hooks can run).
 *
 * Keep `--duration` < the cron interval (e.g. 280 for a 5-minute cron) so two workers never
 * overlap; an atomic cache lock is the backstop if a pass runs long.
 */
class Work extends Command
{
    protected $signature = 'cporter:work
        {--duration=280 : Total seconds to loop before exiting (keep < cron interval)}
        {--sleep=12 : Seconds to sleep between passes}
        {--max=10 : Max deployments to finalize per pass (passed to cporter:run-jobs)}
        {--housekeep-every=60 : Seconds between housekeep passes}';

    protected $description = 'Loop the cron-worker in-process to beat cPanel 5-minute cron cadence';

    public function handle(CronHeartbeat $heartbeat): int
    {
        $duration = max(0, (int) $this->option('duration'));
        $sleep = max(1, (int) $this->option('sleep'));
        $max = (int) $this->option('max');
        $housekeepEvery = max(0, (int) $this->option('housekeep-every'));

        // Prevent two overlapping workers (e.g. if a pass runs long past the cron interval).
        $lock = Cache::lock('cporter:work', $duration + 30);
        if (! $lock->get()) {
            $this->info('cporter:work — another worker holds the lock; exiting.');

            return self::SUCCESS;
        }

        $start = time();
        $deadline = $start + $duration;
        $nextHousekeep = $start; // housekeep on the first pass
        $passes = 0;

        try {
            do {
                $passes++;

                // Mode-B heartbeat for the cron dead-man's switch (docs/SPEC.md §10).
                $heartbeat->beat(CronHeartbeat::WORKER_KEY, ['passes' => $passes]);

                // --no-heartbeat: this nested run-jobs must not masquerade as a Mode-A tick.
                $this->tick('cporter:run-jobs', ['--max' => $max, '--no-heartbeat' => true]);

                // Drain staging jobs (artifact extract etc.). --stop-when-empty returns as soon
                // as the queue is empty; --max-time bounds it so it never eats the whole pass.
                $this->tick('queue:work', [
                    '--stop-when-empty' => true,
                    '--max-time' => $sleep + 30,
                ]);

                if ($housekeepEvery > 0 && time() >= $nextHousekeep) {
                    $this->tick('cporter:housekeep', []);
                    $nextHousekeep = time() + $housekeepEvery;
                }

                // Stop before sleeping past the deadline (also makes --duration=0 a single pass).
                if (time() + $sleep > $deadline) {
                    break;
                }

                sleep($sleep);
            } while (time() < $deadline);
        } finally {
            $lock->release();
        }

        $elapsed = time() - $start;
        $this->info("cporter:work — {$passes} pass(es) over {$elapsed}s.");

        return self::SUCCESS;
    }

    /**
     * Run a sub-command, isolating its failure so one bad pass doesn't kill the loop.
     *
     * @param  array<string, mixed>  $args
     */
    private function tick(string $command, array $args): void
    {
        try {
            $this->callSilent($command, $args);
        } catch (Throwable $e) {
            $this->error("cporter:work — {$command} failed: {$e->getMessage()}");
        }
    }
}
