<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupRetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackupPruneCommand extends Command
{
    protected $signature = 'backup:prune {--keep= : Override daily retention threshold}';

    protected $description = 'Prune expired database and mail storage backups according to retention policies.';

    public function handle(BackupRetentionService $retentionService): int
    {
        $lock = Cache::lock('backup_prune_lock', 600);

        if (!$lock->get()) {
            $this->warn('Another backup pruning process is currently running. Skipping execution.');
            return Command::SUCCESS;
        }

        try {
            $keep = $this->option('keep') !== null ? (int) $this->option('keep') : null;

            $this->info('--> Running backup retention pruning...');

            $storagePath = config('backup.storage_path');
            $dbResult = $retentionService->pruneDirectory($storagePath . '/db', $keep);
            $vmailResult = $retentionService->pruneDirectory($storagePath . '/vmail', $keep);

            $this->line('');
            $this->info('Database Backups:');
            $this->line('  Retained:    ' . count($dbResult['retained']));
            $this->line('  Pruned:      ' . count($dbResult['pruned']));
            if (!empty($dbResult['pruned'])) {
                foreach ($dbResult['pruned'] as $file) {
                    $this->line("    - Pruned: {$file}");
                }
            }
            if (!empty($dbResult['cleaned_tmp'])) {
                $this->line('  Stale .tmp:  ' . count($dbResult['cleaned_tmp']));
            }

            $this->line('');
            $this->info('Mail Storage Backups:');
            $this->line('  Retained:    ' . count($vmailResult['retained']));
            $this->line('  Pruned:      ' . count($vmailResult['pruned']));
            if (!empty($vmailResult['pruned'])) {
                foreach ($vmailResult['pruned'] as $file) {
                    $this->line("    - Pruned: {$file}");
                }
            }
            if (!empty($vmailResult['cleaned_tmp'])) {
                $this->line('  Stale .tmp:  ' . count($vmailResult['cleaned_tmp']));
            }

            $this->line('');
            $this->info('Backup pruning completed successfully.');

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backup pruning encountered an error: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
