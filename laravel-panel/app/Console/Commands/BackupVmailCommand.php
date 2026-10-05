<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupVerificationService;
use App\Services\Backup\MailStorageBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackupVmailCommand extends Command
{
    protected $signature = 'backup:vmail 
                            {--source= : Source mail storage directory (defaults to config)} 
                            {--dir= : Custom backup destination directory} 
                            {--path= : Custom backup destination directory (alias for --dir)} 
                            {--verify : Verify archive integrity immediately after creation}';

    protected $description = 'Archive and compress mail storage (/var/vmail) with SHA-256 checksum manifest.';

    public function handle(
        MailStorageBackupService $backupService,
        BackupVerificationService $verificationService
    ): int {
        $lock = Cache::lock('backup_vmail_lock', 900);

        if (!$lock->get()) {
            $this->warn('Another mail storage backup process is currently running. Skipping execution.');
            return Command::SUCCESS;
        }

        try {
            $this->info('Starting mail storage backup (/var/vmail)...');
            $source = $this->option('source');
            $dir = $this->option('path') ?: $this->option('dir');

            $manifest = $backupService->backup($source, $dir);

            $this->info("Mail backup created successfully: {$manifest['filename']}");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Backup ID', $manifest['backup_id']],
                    ['Source Path', $manifest['source_path']],
                    ['Files Count', (string) $manifest['file_count']],
                    ['Size (Bytes)', number_format($manifest['size_bytes'])],
                    ['SHA-256', $manifest['sha256']],
                    ['Duration (s)', (string) $manifest['duration_seconds']],
                    ['Path', $manifest['path']],
                ]
            );

            if ($this->option('verify')) {
                $this->info('Verifying mail archive integrity...');
                $verification = $verificationService->verifyArchiveIntegrity($manifest['path'], $manifest['sha256']);

                if ($verification['valid']) {
                    $this->info('Integrity verification PASSED: Tarball valid, SHA-256 matches manifest.');
                } else {
                    $this->error('Integrity verification FAILED: ' . implode(', ', $verification['errors']));
                    return Command::FAILURE;
                }
            }

            return Command::SUCCESS;

        } catch (Throwable $e) {
            $this->error('Mail storage backup failed: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
