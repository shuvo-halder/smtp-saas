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
     * Apply calendar-aware retention pruning across database and mail storage backups.
     * Retains:
     * - Daily snapshots (default 7 days)
     * - Weekly snapshots (default 4 weeks)
     * - Monthly snapshots (default 3 months)
     *
     * Invariants:
     * - Never deletes the newest valid backup.
     * - Never deletes the only remaining backup.
     * - Stale .tmp files older than 24 hours are removed.
     *
     * @param string|null $targetDirectory
     * @param int|null $keepDaily
     * @param int|null $keepWeekly
     * @param int|null $keepMonthly
     * @return array
     */
    public function pruneDirectory(
        ?string $targetDirectory = null,
        ?int $keepDaily = null,
        ?int $keepWeekly = null,
        ?int $keepMonthly = null
    ): array {
        $dir = $targetDirectory ?? ($this->baseStoragePath . '/db');
        if (!is_dir($dir)) {
            return ['pruned' => [], 'retained' => [], 'cleaned_tmp' => []];
        }

        $maxDaily = $keepDaily ?? (int) config('backup.retention.keep_daily', 7);
        $maxWeekly = $keepWeekly ?? ($keepDaily !== null ? 0 : (int) config('backup.retention.keep_weekly', 4));
        $maxMonthly = $keepMonthly ?? ($keepDaily !== null ? 0 : (int) config('backup.retention.keep_monthly', 3));

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

        // 2. Discover all valid completed backup archives (.sql.gz, .tar.gz, and .enc)
        $files = array_unique(array_merge(
            File::glob($dir . '/*.sql.gz'),
            File::glob($dir . '/*.tar.gz'),
            File::glob($dir . '/*.sql.gz.enc'),
            File::glob($dir . '/*.tar.gz.enc')
        ));

        if (empty($files)) {
            return ['pruned' => [], 'retained' => [], 'cleaned_tmp' => $cleanedTmp];
        }

        // Parse backup files with their timestamps
        $parsedFiles = [];
        foreach ($files as $file) {
            $parsedFiles[] = [
                'path'      => $file,
                'basename'  => basename($file),
                'timestamp' => $this->resolveFileTimestamp($file),
                'mtime'     => filemtime($file) ?: time(),
            ];
        }

        // Sort descending by timestamp / mtime (newest first)
        usort($parsedFiles, function ($a, $b) {
            $diff = $b['timestamp']->timestamp - $a['timestamp']->timestamp;
            return $diff !== 0 ? $diff : ($b['mtime'] - $a['mtime']);
        });

        // Invariant: Never delete if only 1 backup exists
        if (count($parsedFiles) <= 1) {
            return [
                'pruned'      => [],
                'retained'    => array_column($parsedFiles, 'basename'),
                'cleaned_tmp' => $cleanedTmp,
            ];
        }

        // 3. Calendar Bucket Allocation
        $retainedPaths = [];

        // Invariant: Newest backup is ALWAYS retained
        $newest = $parsedFiles[0];
        $retainedPaths[$newest['path']] = $newest['basename'];

        // Group by calendar day (Y-m-d)
        $dailyGroups = [];
        // Group by calendar week (o-W)
        $weeklyGroups = [];
        // Group by calendar month (Y-m)
        $monthlyGroups = [];

        foreach ($parsedFiles as $item) {
            $dayKey = $item['timestamp']->format('Y-m-d');
            $weekKey = $item['timestamp']->format('o-W');
            $monthKey = $item['timestamp']->format('Y-m');

            $dailyGroups[$dayKey][] = $item;
            $weeklyGroups[$weekKey][] = $item;
            $monthlyGroups[$monthKey][] = $item;
        }

        // A. Keep latest backup for up to $maxDaily distinct days
        if ($maxDaily > 0) {
            $dayCount = 0;
            foreach ($dailyGroups as $dayKey => $items) {
                if ($dayCount >= $maxDaily) {
                    break;
                }
                $selected = $items[0];
                $retainedPaths[$selected['path']] = $selected['basename'];
                $dayCount++;
            }
        }

        // B. Keep latest backup for up to $maxWeekly distinct weeks
        if ($maxWeekly > 0) {
            $weekCount = 0;
            foreach ($weeklyGroups as $weekKey => $items) {
                if ($weekCount >= $maxWeekly) {
                    break;
                }
                $selected = $items[0];
                $retainedPaths[$selected['path']] = $selected['basename'];
                $weekCount++;
            }
        }

        // C. Keep latest backup for up to $maxMonthly distinct months
        if ($maxMonthly > 0) {
            $monthCount = 0;
            foreach ($monthlyGroups as $monthKey => $items) {
                if ($monthCount >= $maxMonthly) {
                    break;
                }
                $selected = $items[0];
                $retainedPaths[$selected['path']] = $selected['basename'];
                $monthCount++;
            }
        }

        // 4. Perform Pruning
        $retained = [];
        $pruned = [];

        foreach ($parsedFiles as $item) {
            $filePath = $item['path'];
            $baseName = $item['basename'];

            if (isset($retainedPaths[$filePath])) {
                $retained[] = $baseName;
            } else {
                // Invariant double-check: never delete newest
                if ($filePath === $newest['path']) {
                    $retained[] = $baseName;
                    continue;
                }

                $size = filesize($filePath);
                $fileMtime = $item['mtime'];
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

    /**
     * Resolve timestamp for backup file from filename or mtime.
     */
    protected function resolveFileTimestamp(string $filePath): Carbon
    {
        $baseName = basename($filePath);

        // Look for _YYYYMMDD_HHMMSS pattern in filename
        if (preg_match('/_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $baseName, $m)) {
            try {
                return Carbon::create(
                    (int) $m[1], (int) $m[2], (int) $m[3],
                    (int) $m[4], (int) $m[5], (int) $m[6],
                    'UTC'
                );
            } catch (Throwable) {
                // fallback to mtime
            }
        }

        // Look for _YYYYMMDD pattern in filename
        if (preg_match('/_(\d{4})(\d{2})(\d{2})/', $baseName, $m)) {
            try {
                return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC');
            } catch (Throwable) {
                // fallback to mtime
            }
        }

        $mtime = filemtime($filePath) ?: time();
        return Carbon::createFromTimestamp($mtime, 'UTC');
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
