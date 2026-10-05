<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupVerificationService;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database 
                            {--dir= : Custom backup destination directory} 
                            {--path= : Custom backup destination directory (alias for --dir)} 
                            {--connection= : Database connection to back up} 
                            {--verify : Verify archive integrity immediately after creation}';

    protected $description = 'Generate a transaction-safe, compressed database backup with SHA-256 checksum manifest.';

    public function handle(
        DatabaseBackupService $backupService,
        BackupVerificationService $verificationService
    ): int {
        $lock = Cache::lock('backup_database_lock', 600);

        if (!$lock->get()) {
            $this->warn('Another database backup process is currently running. Skipping execution.');
            return Command::SUCCESS;
        }

        try {
            $this->info('Starting transaction-safe database backup...');
            $dir = $this->option('path') ?: $this->option('dir');
            $connection = $this->option('connection');

            $manifest = $backupService->backup($dir, $connection);

            $this->info("Backup created successfully: {$manifest['filename']}");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Backup ID', $manifest['backup_id']],
                    ['Database', $manifest['database_name']],
                    ['Tables Count', (string) $manifest['tables_count']],
                    ['Size (Bytes)', number_format($manifest['size_bytes'])],
                    ['SHA-256', $manifest['sha256']],
                    ['Duration (s)', (string) $manifest['duration_seconds']],
                    ['Path', $manifest['path']],
                ]
            );

            if ($this->option('verify')) {
                $this->info('Verifying archive integrity...');
                $verification = $verificationService->verifyDatabaseBackup($manifest['path']);

                if ($verification['valid']) {
                    $this->info('Integrity verification PASSED: Gzip stream valid, SHA-256 matches manifest.');
                } else {
                    $this->error('Integrity verification FAILED: ' . implode(', ', $verification['errors']));
                    return Command::FAILURE;
                }
            }

            return Command::SUCCESS;

        } catch (Throwable $e) {
            $this->error('Database backup failed: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
