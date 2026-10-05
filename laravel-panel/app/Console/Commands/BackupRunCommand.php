<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupRetentionService;
use App\Services\Backup\BackupVerificationService;
use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\MailStorageBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run 
                            {--only-db : Back up database only} 
                            {--only-vmail : Back up mail storage only} 
                            {--verify : Verify backups immediately after creation} 
                            {--no-prune : Skip retention pruning}';

    protected $description = 'Execute scheduled full EmailSaaS backup suite (database, mail storage, verification, retention).';

    public function handle(
        DatabaseBackupService $dbBackupService,
        MailStorageBackupService $mailBackupService,
        BackupVerificationService $verificationService,
        BackupRetentionService $retentionService
    ): int {
        $lock = Cache::lock('backup_run_master_lock', 1200);

        if (!$lock->get()) {
            $this->warn('Another backup run is currently in progress. Skipping execution to prevent overlap.');
            return Command::SUCCESS;
        }

        $onlyDb = (bool) $this->option('only-db');
        $onlyVmail = (bool) $this->option('only-vmail');
        $verify = (bool) $this->option('verify');
        $noPrune = (bool) $this->option('no-prune');

        $success = true;

        try {
            // 1. Database Backup
            if (!$onlyVmail) {
                $this->info('--> Running database backup...');
                try {
                    $dbManifest = $dbBackupService->backup();
                    $this->info("Database backup created: {$dbManifest['filename']} (" . number_format($dbManifest['size_bytes']) . " bytes)");

                    if ($verify) {
                        $this->info('--> Verifying database backup integrity...');
                        $dbVerify = $verificationService->verifyDatabaseBackup($dbManifest['path']);
                        if ($dbVerify['valid']) {
                            $this->info('Database integrity check: PASSED');
                        } else {
                            $this->error('Database integrity check: FAILED - ' . implode(', ', $dbVerify['errors']));
                            $success = false;
                        }
                    }
                } catch (Throwable $e) {
                    $this->error('Database backup failed: ' . $e->getMessage());
                    $success = false;
                }
            }

            // 2. Mail Storage Backup
            if (!$onlyDb) {
                $this->info('--> Running mail storage backup (/var/vmail)...');
                try {
                    $mailManifest = $mailBackupService->backup();
                    $this->info("Mail storage backup created: {$mailManifest['filename']} (" . number_format($mailManifest['size_bytes']) . " bytes)");

                    if ($verify) {
                        $this->info('--> Verifying mail storage backup integrity...');
                        $mailVerify = $verificationService->verifyArchiveIntegrity($mailManifest['path'], $mailManifest['sha256']);
                        if ($mailVerify['valid']) {
                            $this->info('Mail storage integrity check: PASSED');
                        } else {
                            $this->error('Mail storage integrity check: FAILED - ' . implode(', ', $mailVerify['errors']));
                            $success = false;
                        }
                    }
                } catch (Throwable $e) {
                    $this->error('Mail storage backup failed: ' . $e->getMessage());
                    $success = false;
                }
            }

            // 3. Retention Pruning
            if (!$noPrune && $success) {
                $this->info('--> Running backup retention pruning...');
                $pruned = $retentionService->pruneAll();
                $prunedDbCount = count($pruned['database']['pruned'] ?? []);
                $prunedMailCount = count($pruned['mail_storage']['pruned'] ?? []);
                $this->info("Retention pruning complete. Pruned: {$prunedDbCount} database archives, {$prunedMailCount} mail archives.");
            }

            return $success ? Command::SUCCESS : Command::FAILURE;

        } finally {
            $lock->release();
        }
    }
}
