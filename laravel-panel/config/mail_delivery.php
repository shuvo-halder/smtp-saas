<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mail Delivery Mode
    |--------------------------------------------------------------------------
    |
    | When dry_run is true, mail delivery is simulated locally: records transition
    | to 'sent' without attempting external SMTP network connections.
    |
    */
    'dry_run' => env('MAIL_DELIVERY_DRY_RUN', true),

    /*
    |--------------------------------------------------------------------------
    | Retry and Exponential Backoff Settings
    |--------------------------------------------------------------------------
    |
    | Maximum retry attempts and base exponential backoff delay in seconds.
    |
    */
    'default_max_attempts' => (int) env('MAIL_DELIVERY_MAX_ATTEMPTS', 3),
    'base_backoff_seconds' => (int) env('MAIL_DELIVERY_BASE_BACKOFF', 60),
    'max_backoff_seconds'  => (int) env('MAIL_DELIVERY_MAX_BACKOFF', 900),

    /*
    |--------------------------------------------------------------------------
    | Stale Processing Recovery Threshold
    |--------------------------------------------------------------------------
    |
    | Seconds before a job stuck in 'processing' is considered stale/interrupted.
    |
    */
    'stale_threshold_seconds' => (int) env('MAIL_DELIVERY_STALE_THRESHOLD', 900),
];
