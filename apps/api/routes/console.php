<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduler (see docs/SPEC.md §10). A single cPanel cron hits `php artisan schedule:run`
| every minute; that shell context runs the cron-worker that executes queued shell jobs
| (target-app artisan migrate/cache, queue worker, prune, timeout cleanup).
*/
// Schedule::command('cporter:run-jobs')->everyMinute()->withoutOverlapping();
// Schedule::command('cporter:prune-releases')->hourly();
