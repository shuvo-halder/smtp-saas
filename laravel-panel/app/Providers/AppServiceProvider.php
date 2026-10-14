<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Scheduling\ScheduledTaskTrackerService::class);
        $this->app->singleton(\App\Services\Health\SystemHealthService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Scheduled task execution tracking for operational visibility (Step 19)
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\ScheduledTaskStarting::class,
            fn (\Illuminate\Console\Events\ScheduledTaskStarting $event) => $this->app->make(\App\Services\Scheduling\ScheduledTaskTrackerService::class)->recordStart($event->task)
        );

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\ScheduledTaskFinished::class,
            fn (\Illuminate\Console\Events\ScheduledTaskFinished $event) => $this->app->make(\App\Services\Scheduling\ScheduledTaskTrackerService::class)->recordFinish($event->task, $event->runtime)
        );

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\ScheduledTaskFailed::class,
            fn (\Illuminate\Console\Events\ScheduledTaskFailed $event) => $this->app->make(\App\Services\Scheduling\ScheduledTaskTrackerService::class)->recordFailure($event->task, $event->exception)
        );

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\ScheduledTaskSkipped::class,
            fn (\Illuminate\Console\Events\ScheduledTaskSkipped $event) => $this->app->make(\App\Services\Scheduling\ScheduledTaskTrackerService::class)->recordSkipped($event->task)
        );
    }
}
