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

        // Authentication & Security Audit Trail Listeners (Step 23)
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            function (\Illuminate\Auth\Events\Login $event) {
                /** @var \App\Models\User $user */
                $user = $event->user;
                $this->app->make(\App\Services\AuditService::class)->record(
                    action: 'auth.login',
                    entityType: 'User',
                    entityId: $user->id,
                    before: null,
                    after: [
                        'email'    => $user->email,
                        'is_admin' => (bool) $user->is_admin,
                        'outcome'  => 'success',
                    ],
                    actor: $user,
                    reason: 'Successful user authentication'
                );
            }
        );

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Logout::class,
            function (\Illuminate\Auth\Events\Logout $event) {
                /** @var \App\Models\User|null $user */
                $user = $event->user;
                if ($user) {
                    $this->app->make(\App\Services\AuditService::class)->record(
                        action: 'auth.logout',
                        entityType: 'User',
                        entityId: $user->id,
                        before: null,
                        after: [
                            'email'   => $user->email,
                            'outcome' => 'success',
                        ],
                        actor: $user,
                        reason: 'User session terminated'
                    );
                }
            }
        );

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Failed::class,
            function (\Illuminate\Auth\Events\Failed $event) {
                // Strictly exclude password or credential hashes from audit record
                $attemptedEmail = isset($event->credentials['email']) ? (string) $event->credentials['email'] : null;

                $this->app->make(\App\Services\AuditService::class)->record(
                    action: 'auth.failed',
                    entityType: 'User',
                    entityId: $event->user?->id,
                    before: null,
                    after: [
                        'attempted_email' => $attemptedEmail,
                        'guard'           => $event->guard,
                        'outcome'         => 'failure',
                    ],
                    actor: null,
                    reason: 'Failed authentication attempt: invalid credentials'
                );
            }
        );
    }
}
