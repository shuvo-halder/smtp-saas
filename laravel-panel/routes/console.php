<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule the tenant suspension check to run every minute
use Illuminate\Support\Facades\Schedule;

Schedule::command('tenant:suspend-expired')->everyMinute();

