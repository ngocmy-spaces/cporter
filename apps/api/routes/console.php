<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduler (docs/SPEC.md §10).
|
| Two supported cron setups depending on the host's minimum cron cadence:
|
| A) Host allows 1-minute cron — ONE cron drives the Laravel scheduler:
|      * * * * * cd /home/<user>/cporter.domain/current && php artisan schedule:run >> /dev/null 2>&1
|    The everyMinute() tasks below then finalize deploys within ~1 min.
|
| B) Host caps cron at 5 minutes (common on cPanel) — call the internal-loop worker directly
|    on a 5-minute cron. It loops in-process so latency stays ~seconds instead of ~5 min:
|      [every 5 min] cd /home/<user>/cporter.domain/current && php artisan cporter:work >> /dev/null 2>&1
|    (cporter:work runs run-jobs + queue:work + housekeep on its own inner loop; when using it
|    you do NOT also need the schedule:run cron.) See docs/DEPLOYMENT-CPANEL.md for the crontab.
|
| Either way the work runs in cron's shell context, so target-app hooks that web PHP cannot
| run (proc_open) execute there. See docs/DEPLOYMENT-CPANEL.md §"Cron".
*/
// Finalize Laravel deploys (run hooks → activate → health) — the cron shell context.
Schedule::command('cporter:run-jobs')->everyMinute()->withoutOverlapping();

// Process deploy jobs (staging/extract, and the full no-hook pipeline) enqueued by the FIFO
// dispatcher. This single scheduled worker keeps the cPanel target single-process (deploys of
// different projects interleave cooperatively). For TRUE cross-project parallelism on Docker/VPS,
// run N dedicated long-lived `php artisan queue:work` daemons instead of / alongside this — the
// per-project claim lock + deploy.lock make that safe (docs/SPEC.md §6, §10).
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();

// Fail timed-out deployments and release stale locks.
Schedule::command('cporter:housekeep')->everyFiveMinutes()->withoutOverlapping();

// Continuously monitor project health so alerts/dashboard read a persisted signal (docs/SPEC.md §21.1).
Schedule::command('cporter:check-health')->everyFiveMinutes()->withoutOverlapping();

// Detect deploy-hook binaries in the cron shell (self-throttled to ~6h; the command no-ops when fresh).
Schedule::command('cporter:probe-binaries')->hourly()->withoutOverlapping();
