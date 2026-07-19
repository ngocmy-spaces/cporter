<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduler (docs/SPEC.md §10). ONE cPanel cron drives everything:
|   * * * * * cd /home/<user>/cporter.domain/current && php artisan schedule:run >> /dev/null 2>&1
| That shell context lets the cron-worker run target-app hooks that web PHP cannot.
*/
// Finalize Laravel deploys (run hooks → activate → health) — the cron shell context.
Schedule::command('cporter:run-jobs')->everyMinute()->withoutOverlapping();

// Process staging jobs (artifact extract etc.) enqueued by the Deploy API.
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();

// Fail timed-out deployments and release stale locks.
Schedule::command('cporter:housekeep')->everyFiveMinutes()->withoutOverlapping();
