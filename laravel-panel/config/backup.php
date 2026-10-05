<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Storage Path
    |--------------------------------------------------------------------------
    | Dedicated backup root directory. On production Linux, this defaults
    | to /var/backups/mailsaas. When running locally or in tests where that
    | path is unwritable, fallback to storage_path('backups').
    */
    'storage_path' => env('BACKUP_STORAGE_PATH', (is_dir('/var/backups/mailsaas') && is_writable('/var/backups/mailsaas'))
        ? '/var/backups/mailsaas'
        : storage_path('backups')),

    /*
    |--------------------------------------------------------------------------
    | Mail Storage Source Directory
    |--------------------------------------------------------------------------
    | Path to physical Maildir vhosts directory.
    */
    'vmail_source_path' => env('MAIL_VHOSTS_DIR', '/var/vmail'),

    /*
    |--------------------------------------------------------------------------
    | Retention Policy Configuration
    |--------------------------------------------------------------------------
    | Retain 7 daily snapshots, 4 weekly snapshots, and 3 monthly snapshots.
    */
    'retention' => [
        'keep_daily'   => (int) env('BACKUP_RETENTION_DAILY', 7),
        'keep_weekly'  => (int) env('BACKUP_RETENTION_WEEKLY', 4),
        'keep_monthly' => (int) env('BACKUP_RETENTION_MONTHLY', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Critical Database Tables
    |--------------------------------------------------------------------------
    | Essential relational tables required for restore verification.
    */
    'critical_tables' => [
        'users',
        'domains',
        'mailboxes',
        'plans',
        'invoices',
        'audit_logs',
        'abuse_incidents',
    ],

    /*
    |--------------------------------------------------------------------------
    | Offsite Disaster Recovery Synchronization
    |--------------------------------------------------------------------------
    | Destination configuration for offsite disaster recovery replication.
    */
    'offsite' => [
        'enabled'  => (bool) env('BACKUP_OFFSITE_ENABLED', false),
        'host'     => env('BACKUP_OFFSITE_HOST', null),
        'port'     => (int) env('BACKUP_OFFSITE_PORT', 22),
        'user'     => env('BACKUP_OFFSITE_USER', 'backup-operator'),
        'path'     => env('BACKUP_OFFSITE_PATH', '/var/backups/remote-mailsaas'),
        'ssh_key'  => env('BACKUP_OFFSITE_SSH_KEY', null),
    ],
];
