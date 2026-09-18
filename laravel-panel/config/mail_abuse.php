<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SMTP Abuse Detection & Bounce Tracking Enabled
    |--------------------------------------------------------------------------
    |
    | When set to true, the background mail log parser will process outbound
    | delivery events, classify bounces, update Redis abuse counters, and
    | emit structured alerts when thresholds are breached.
    |
    */
    'enabled' => env('MAIL_ABUSE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Postfix Mail Log Path
    |--------------------------------------------------------------------------
    |
    | Path to the Postfix syslog output file. Defaults to standard Ubuntu path.
    | If the current process lacks read permissions, the service fails safely
    | without interrupting mail flow.
    |
    */
    'log_path' => env('MAIL_LOG_PATH', '/var/log/mail.log'),

    /*
    |--------------------------------------------------------------------------
    | Queue ID Correlation TTL
    |--------------------------------------------------------------------------
    |
    | Time-to-live (in seconds) for temporary Queue ID -> Envelope Sender mappings.
    | 86400 seconds (24 hours) accommodates standard Postfix queue retry windows.
    |
    */
    'queue_correlation_ttl' => env('MAIL_ABUSE_QUEUE_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Abuse Daily Counter TTL
    |--------------------------------------------------------------------------
    |
    | Time-to-live (in seconds) for daily bounce counters in Redis. 172800 seconds
    | (48 hours) ensures multi-day sliding window lookups and historical parity.
    |
    */
    'counter_ttl' => env('MAIL_ABUSE_COUNTER_TTL', 172800),

    /*
    |--------------------------------------------------------------------------
    | Detection Thresholds
    |--------------------------------------------------------------------------
    |
    | Detection parameters for flagging potential spamming or list-dumping.
    | Note: These are detection and alerting thresholds only; no destructive
    | account suspension is performed automatically.
    |
    */
    'min_recipients_for_rate_check' => env('MAIL_ABUSE_MIN_RECIPIENTS', 20),
    'hard_bounce_rate_threshold' => env('MAIL_ABUSE_RATE_THRESHOLD', 0.10),
    'daily_hard_bounce_max' => env('MAIL_ABUSE_DAILY_HARD_MAX', 50),
    'consecutive_hard_bounces_max' => env('MAIL_ABUSE_CONSECUTIVE_HARD_MAX', 15),

    /*
    |--------------------------------------------------------------------------
    | Alert Cooldown
    |--------------------------------------------------------------------------
    |
    | Seconds to wait before re-emitting the same alert type for a tenant.
    | Prevents log flooding during active high-volume delivery loops.
    |
    */
    'alert_cooldown_seconds' => env('MAIL_ABUSE_ALERT_COOLDOWN', 86400),

    /*
    |--------------------------------------------------------------------------
    | Parser Bounds
    |--------------------------------------------------------------------------
    |
    | Batch processing constraints to guarantee predictable memory usage.
    |
    */
    'parser_batch_lines' => env('MAIL_ABUSE_BATCH_LINES', 1000),
    'max_line_length' => env('MAIL_ABUSE_MAX_LINE_LENGTH', 4096),

    /*
    |--------------------------------------------------------------------------
    | Redis Keys & Namespaces
    |--------------------------------------------------------------------------
    */
    'parser_cursor_key' => 'outbound:abuse:parser:cursor',
];
