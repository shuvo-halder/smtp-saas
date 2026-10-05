<?php

namespace App\Services\Backup;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupRetentionService
{
    public function __construct(
        protected ?string $baseStoragePath = null
    ) {
        $this->baseStoragePath = $baseStoragePath ?? config('backup.storage_path');
    }

    /**
     * Apply retention pruning across database and mail storage backups.
     * Invariants:
     * - Never deletes the newest valid backup.
     * - Never deletes the only remaining backup.
     * - Stale .tmp files older than 24 hours are removed.
     *
     * @param string|null $targetDirectory
     * @param int|null $keepDaily
     * @return array
     */
    public function pruneDirectory(?string $targetDirectory = null, ?int $keepDaily = null): array
    {
        $dir = $targetDirectory ?? ($this->baseStoragePath . '/db');
        if (!is_dir($dir)) {
            return ['pruned' => [], 'retained' => [], 'cleaned_tmp' => []];
        }

        $maxKeep = $keepDaily ?? config('backup.retention.keep_daily', 7);

        // 1. Clean stale .tmp files older than 24 hours
        $cleanedTmp = [];
        $tmpFiles = File::glob($dir . '/*.tmp');
        foreach ($tmpFiles as $tmp) {
            if (time() - filemtime($tmp) > 86400) {
                @unlink($tmp);
                $cleanedTmp[] = basename($tmp);
                $this->log('info', "Removed stale temporary backup file: " . basename($tmp));
            }
        }

        // 2. Discover valid completed backup files (.sql.gz and .tar.gz)
        $files = array_merge(
            File::glob($dir . '/*.sql.gz'),
            File::glob($dir . '/*.tar.gz')
        );

        if (empty($files)) {
            return ['pruned' => [], 'retained' => [], 'cleaned_tmp' => $cleanedTmp];
        }

        // Sort descending by modification time (newest first)
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $totalBackups = count($files);

        // Invariant: Never delete if only 1 backup exists
        if ($totalBackups <= 1) {
            return [
                'pruned'      => [],
                'retained'    => array_map('basename', $files),
                'cleaned_tmp' => $cleanedTmp,
            ];
        }

        $retained = [];
        $pruned = [];

        // The newest backup is index 0; always retain it
        foreach ($files as $index => $filePath) {
            $baseName = basename($filePath);

            if ($index < $maxKeep || $index === 0) {
                $retained[] = $baseName;
            } else {
                // Invariant check: Ensure we never delete the newest backup
                if ($index === 0) {
                    $retained[] = $baseName;
                    continue;
                }

                $size = filesize($filePath);
                $fileMtime = @filemtime($filePath) ?: time();
                @unlink($filePath);

                // Unlink companion manifest if it exists
                $manifest = "{$filePath}.manifest.json";
                if (file_exists($manifest)) {
                    @unlink($manifest);
                }

                $pruned[] = $baseName;
                $this->log('info', "Pruned expired backup file: {$baseName}", [
                    'size_bytes' => $size,
                    'age_days'   => round((time() - $fileMtime) / 86400, 1),
                ]);
            }
        }

        return [
            'pruned'      => $pruned,
            'retained'    => $retained,
            'cleaned_tmp' => $cleanedTmp,
        ];
    }

    /**
     * Prune both DB and mail storage directories.
     */
    public function pruneAll(): array
    {
        $dbResult = $this->pruneDirectory($this->baseStoragePath . '/db');
        $vmailResult = $this->pruneDirectory($this->baseStoragePath . '/vmail');

        return [
            'database'     => $dbResult,
            'mail_storage' => $vmailResult,
        ];
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('backup')->log($level, "[RETENTION] {$message}", $context);
        } catch (Throwable) {
            Log::log($level, "[RETENTION] {$message}", $context);
        }
    }
}
