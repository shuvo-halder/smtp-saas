<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupStatusCommand extends Command
{
    protected $signature = 'backup:status';

    protected $description = 'Display the status, health, and disk footprint of database and mail storage backups.';

    public function handle(): int
    {
        $baseStoragePath = config('backup.storage_path');
        $dbDir = $baseStoragePath . '/db';
        $vmailDir = $baseStoragePath . '/vmail';

        $this->line('');
        $this->info('================================================================');
        $this->info('              EmailSaaS Backup System Status                    ');
        $this->info('================================================================');
        $this->line('');

        // 1. Database Backups
        $this->displaySectionStatus('Database Backups', $dbDir, '*.sql.gz');

        // 2. Mail Storage Backups
        $this->displaySectionStatus('Mail Storage Backups (/var/vmail)', $vmailDir, '*.tar.gz');

        // 3. Overall Storage & Offsite Summary
        $this->line('');
        $this->info('--- Offsite & Disaster Recovery Configuration ---');
        $offsiteEnabled = config('backup.offsite.enabled', false);
        $offsiteDriver = config('backup.offsite.driver', 'pending');

        if ($offsiteEnabled) {
            $this->line("  Offsite Sync:      <info>ENABLED</info> ({$offsiteDriver})");
        } else {
            $this->line("  Offsite Sync:      <comment>OFFSITE BACKUP — PENDING INFRASTRUCTURE</comment>");
            $this->line("  Notice:            Local backups active. Offsite replication pending remote storage provision.");
        }

        $this->line('');
        return Command::SUCCESS;
    }

    protected function displaySectionStatus(string $title, string $directory, string $pattern): void
    {
        $this->comment(">>> {$title}");
        $this->line("  Directory: " . ($directory ?: 'Not configured'));

        if (!is_dir($directory)) {
            $this->warn("  Status:    Directory does not exist yet.");
            return;
        }

        $files = File::glob($directory . '/' . $pattern);
        $totalFiles = count($files);

        if ($totalFiles === 0) {
            $this->warn("  Status:    No backup archives found.");
            return;
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $totalBytes = 0;
        $tableRows = [];

        foreach ($files as $index => $file) {
            $size = filesize($file);
            $totalBytes += $size;
            $mtime = filemtime($file);
            $manifestPath = "{$file}.manifest.json";
            $hasManifest = file_exists($manifestPath) ? 'Yes' : 'No';

            if ($index < 5) {
                $tableRows[] = [
                    basename($file),
                    $this->formatBytes($size),
                    date('Y-m-d H:i:s', $mtime),
                    Carbon::createFromTimestamp($mtime)->diffForHumans(),
                    $hasManifest,
                ];
            }
        }

        $latestFile = $files[0];
        $latestMtime = filemtime($latestFile);
        $ageHours = round((time() - $latestMtime) / 3600, 1);
        $healthStatus = $ageHours <= 24 ? '<info>HEALTHY</info>' : '<comment>WARNING (>24h old)</comment>';

        $this->line("  Total Archives: {$totalFiles}");
        $this->line("  Total Size:     " . $this->formatBytes($totalBytes));
        $this->line("  Latest Archive: " . basename($latestFile) . " ({$ageHours} hours ago) -> {$healthStatus}");
        $this->line('');

        $this->table(
            ['Archive Name', 'Size', 'Created At', 'Age', 'Manifest'],
            $tableRows
        );
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
