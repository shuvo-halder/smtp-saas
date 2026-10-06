<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify 
                            {path? : Specific backup file path to verify} 
                            {--file= : Specific backup file path to verify} 
                            {--key= : Decryption key for encrypted backups} 
                            {--dry-run-restore : Perform isolated restore test} 
                            {--isolated-db-restore : Perform isolated database restore test} 
                            {--isolated-mail-restore : Perform isolated mail storage restore test}';

    protected $description = 'Verify physical integrity, SHA-256 checksum, encryption envelope, and isolated restore capability of backup archives.';

    public function handle(BackupVerificationService $verificationService): int
    {
        $filePath = $this->argument('path') ?: $this->option('file');
        $key = $this->option('key');

        if (!$filePath) {
            // Find latest database backup if none specified
            $dbDir = config('backup.storage_path') . '/db';
            $files = array_merge(
                File::glob($dbDir . '/*.sql.gz'),
                File::glob($dbDir . '/*.sql.gz.enc')
            );

            if (!empty($files)) {
                usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
                $filePath = $files[0];
                $this->info("No file specified. Using latest database backup: {$filePath}");
            } else {
                $this->error('No backup file specified and no database backups found in storage path.');
                return Command::FAILURE;
            }
        }

        if (!file_exists($filePath)) {
            $this->error("Backup file not found: [{$filePath}]");
            return Command::FAILURE;
        }

        $this->info("Verifying backup archive: {$filePath}");
        $isDb = str_contains(basename($filePath), 'db_') || str_ends_with($filePath, '.sql.gz') || str_ends_with($filePath, '.sql.gz.enc');
        $isEncrypted = str_ends_with($filePath, '.enc');

        $result = $isDb
            ? $verificationService->verifyDatabaseBackup($filePath, $key)
            : $verificationService->verifyArchiveIntegrity($filePath, null, $key);

        $this->table(
            ['Check', 'Status'],
            [
                ['File Exists', file_exists($filePath) ? 'YES' : 'NO'],
                ['Size (Bytes)', number_format($result['size_bytes'] ?? 0)],
                ['SHA-256', $result['sha256'] ?? 'N/A'],
                ['Encrypted at Rest', $isEncrypted ? 'YES (AES-256)' : 'NO'],
                ['Decompressed Bytes', number_format($result['decompressed_bytes'] ?? 0)],
                ['Integrity Valid', ($result['valid'] ?? false) ? 'PASSED' : 'FAILED'],
            ]
        );

        if (!($result['valid'] ?? false)) {
            $this->error('Integrity checks FAILED: ' . implode(', ', $result['errors'] ?? []));
            return Command::FAILURE;
        }

        $this->info('Physical archive and cryptographic checksum integrity: PASSED');

        // Optional Isolated Database Restore Test
        $shouldRestoreDb = ($this->option('isolated-db-restore') || $this->option('dry-run-restore')) && $isDb;
        if ($shouldRestoreDb) {
            $this->info('--> Performing isolated database restore test...');
            try {
                $restoreReport = $verificationService->verifyIsolatedDatabaseRestore($filePath, null, $key);
                $this->info("Isolated restore test PASSED ({$restoreReport['duration_seconds']}s). Restored {$restoreReport['tables_restored']} tables, {$restoreReport['total_rows']} total rows.");

                $rows = [];
                foreach ($restoreReport['critical_tables'] as $tbl => $found) {
                    $rows[] = [$tbl, $found ? 'FOUND' : 'MISSING', (string) ($restoreReport['table_counts'][$tbl] ?? 0)];
                }
                $this->table(['Critical Table', 'Status', 'Row Count'], $rows);

            } catch (Throwable $e) {
                $this->error('Isolated database restore test FAILED: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Optional Isolated Mail Storage Restore Test
        $shouldRestoreMail = ($this->option('isolated-mail-restore') || ($this->option('dry-run-restore') && !$isDb));
        if ($shouldRestoreMail) {
            $this->info('--> Performing isolated mail storage restore test...');
            try {
                $restoreReport = $verificationService->verifyMailStorageBackup($filePath, null, $key);
                $this->info("Isolated mail storage restore test PASSED ({$restoreReport['duration_seconds']}s). Restored {$restoreReport['files_restored']} files. Maildir structure valid.");
            } catch (Throwable $e) {
                $this->error('Isolated mail storage restore test FAILED: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
