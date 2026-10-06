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

        // Encryption Status Header
        $encEnabled = (bool) config('backup.encryption.enabled', false);
        $encCipher = config('backup.encryption.cipher', 'aes-256-cbc');
        $this->line('--- Encryption at Rest Configuration ---');
        if ($encEnabled) {
            $this->line("  Encryption at Rest: <info>ENABLED</info> ({$encCipher})");
        } else {
            $this->line("  Encryption at Rest: <comment>DISABLED</comment> (Archives stored unencrypted)");
        }
        $this->line('');

        // 1. Database Backups
        $this->displaySectionStatus('Database Backups', $dbDir, ['*.sql.gz', '*.sql.gz.enc']);

        // 2. Mail Storage Backups
        $this->displaySectionStatus('Mail Storage Backups (/var/vmail)', $vmailDir, ['*.tar.gz', '*.tar.gz.enc']);

        // 3. Overall Storage & Offsite Summary
        $this->line('');
        $this->info('--- Offsite & Disaster Recovery Configuration ---');
        $offsiteEnabled = (bool) config('backup.offsite.enabled', false);
        $offsiteTransport = config('backup.offsite.transport', 'rsync');
        $offsiteHost = config('backup.offsite.host', 'remote-host');

        if ($offsiteEnabled) {
            $this->line("  Offsite Sync:      <info>ENABLED</info> ({$offsiteTransport} -> {$offsiteHost})");
        } else {
            $this->line("  Offsite Sync:      <comment>OFFSITE BACKUP — PENDING INFRASTRUCTURE</comment>");
            $this->line("  Notice:            Local backups active. Offsite replication pending remote storage provision.");
        }

        $this->line('');
        return Command::SUCCESS;
    }

    /**
     * Display status table for a backup directory.
     *
     * @param string $title
     * @param string $directory
     * @param array $patterns
     */
    protected function displaySectionStatus(string $title, string $directory, array $patterns): void
    {
        $this->comment(">>> {$title}");
        $this->line("  Directory: " . ($directory ?: 'Not configured'));

        if (!is_dir($directory)) {
            $this->warn("  Status:    Directory does not exist yet.");
            return;
        }

        $files = [];
        foreach ($patterns as $pattern) {
            $files = array_merge($files, File::glob($directory . '/' . $pattern));
        }
        $files = array_unique($files);
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
            $hasManifest = 'No';
            $isEncrypted = str_ends_with($file, '.enc') ? 'Yes' : 'No';
            $verified = 'No';
            $offsite = 'Pending';

            if (file_exists($manifestPath)) {
                $hasManifest = 'Yes';
                $manifestData = json_decode((string) @file_get_contents($manifestPath), true);
                if (is_array($manifestData)) {
                    $verified = !empty($manifestData['verified']) ? 'Verified' : 'No';
                    $offsite = $manifestData['offsite_status'] ?? (!empty($manifestData['offsite_copied']) ? 'Copied' : 'Pending');
                }
            }

            if ($index < 5) {
                $tableRows[] = [
                    basename($file),
                    $this->formatBytes($size),
                    $isEncrypted,
                    date('Y-m-d H:i:s', $mtime),
                    Carbon::createFromTimestamp($mtime)->diffForHumans(),
                    $verified,
                    $offsite,
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
            ['Archive Name', 'Size', 'Encrypted', 'Created At', 'Age', 'Verified', 'Offsite', 'Manifest'],
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
