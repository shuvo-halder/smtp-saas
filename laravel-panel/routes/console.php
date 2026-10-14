<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule the tenant suspension check to run every minute
use Illuminate\Support\Facades\Schedule;

Schedule::command('tenant:suspend-expired')->everyMinute()->withoutOverlapping(10);

// Schedule Redis -> MariaDB outbound recipient usage synchronization hourly
Schedule::command('outbound:usage-sync')->hourly()->withoutOverlapping(15);

// Schedule Postfix mail log parsing and abuse detection every 5 minutes
Schedule::command('mail:process-log')->everyFiveMinutes()->withoutOverlapping(10);

// Schedule daily database & mail storage backup suite with verification at 02:00
Schedule::command('backup:run --verify')->dailyAt('02:00')->withoutOverlapping(30);

// Schedule daily backup retention pruning at 03:00
Schedule::command('backup:prune')->dailyAt('03:00')->withoutOverlapping(15);

// Schedule daily pruning of scheduled task execution records (keep 7 days) at 04:00
Schedule::call(function () {
    app(\App\Services\Scheduling\ScheduledTaskTrackerService::class)->prune(7);
})->dailyAt('04:00')->name('scheduled-tasks:prune')->withoutOverlapping(15);

