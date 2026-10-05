<?php

namespace App\Services\Backup;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class MailStorageBackupService
{
    public function __construct(
        protected ?string $storagePath = null,
        protected ?string $sourcePath = null
    ) {
        $this->storagePath = $storagePath ?? (config('backup.storage_path') . '/vmail');
        $this->sourcePath = $sourcePath ?? config('backup.vmail_source_path', '/var/vmail');
    }

    /**
     * Backup mail storage directory (/var/vmail) into a compressed archive with SHA-256 manifest.
     *
     * @param string|null $sourceDir
     * @param string|null $destinationDir
     * @return array
     * @throws Throwable
     */
    public function backup(?string $sourceDir = null, ?string $destinationDir = null): array
    {
        $source = $sourceDir ?? $this->sourcePath;
        $targetDir = $destinationDir ?? $this->storagePath;

        if (!is_dir($source)) {
            throw new RuntimeException("Mail storage source directory does not exist: [{$source}]");
        }

        File::ensureDirectoryExists($targetDir);

        $timestamp = Carbon::now('UTC')->format('Ymd_His');
        $backupId = (string) Str::uuid();

        $baseFilename = "vmail_backup_{$timestamp}";
        $tarGzFilename = "{$baseFilename}.tar.gz";
        $finalPath = $targetDir . DIRECTORY_SEPARATOR . $tarGzFilename;

        // If a backup with the exact same timestamp already exists, append unique suffix
        if (file_exists($finalPath)) {
            $baseFilename .= '_' . substr($backupId, 0, 8);
            $tarGzFilename = "{$baseFilename}.tar.gz";
            $finalPath = $targetDir . DIRECTORY_SEPARATOR . $tarGzFilename;
        }

        $tempTarPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "vmail_tar_{$backupId}.tar";
        $tempTarGzPath = $targetDir . DIRECTORY_SEPARATOR . "{$tarGzFilename}.tmp";
        $manifestPath = $targetDir . DIRECTORY_SEPARATOR . "{$tarGzFilename}.manifest.json";

        $startTime = microtime(true);

        try {
            // Count files and compute source size
            $fileCount = 0;
            $uncompressedBytes = 0;

            if (file_exists($tempTarPath)) {
                @unlink($tempTarPath);
            }

            $phar = new PharData($tempTarPath);

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $subPath = substr($item->getPathname(), strlen($source));
                $subPath = ltrim(str_replace('\\', '/', $subPath), '/');

                if (empty($subPath)) {
                    continue;
                }

                if ($item->isDir()) {
                    $phar->addEmptyDir($subPath);
                } elseif ($item->isFile()) {
                    $phar->addFile($item->getPathname(), $subPath);
                    $fileCount++;
                    $uncompressedBytes += $item->getSize();
                }
            }

            unset($phar); // Flush tar to disk

            // Compress the tar archive to .tar.gz
            // We use gzopen to compress the tarball into .tar.gz.tmp
            $tarHandle = fopen($tempTarPath, 'rb');
            $gzHandle = gzopen($tempTarGzPath, 'wb9');

            if (!$tarHandle || !$gzHandle) {
                throw new RuntimeException("Failed to open streams for tarball compression.");
            }

            while (!feof($tarHandle)) {
                $chunk = fread($tarHandle, 1024 * 512); // 512 KB chunks
                if ($chunk !== false && strlen($chunk) > 0) {
                    gzwrite($gzHandle, $chunk);
                }
            }

            fclose($tarHandle);
            gzclose($gzHandle);

            // Remove raw uncompressed tarball
            @unlink($tempTarPath);

            if (!file_exists($tempTarGzPath) || filesize($tempTarGzPath) === 0) {
                throw new RuntimeException("Generated compressed mail storage backup is empty or missing: [{$tempTarGzPath}]");
            }

            $sizeBytes = filesize($tempTarGzPath);
            $sha256 = hash_file('sha256', $tempTarGzPath);

            // Atomic promotion to final path
            if (!rename($tempTarGzPath, $finalPath)) {
                throw new RuntimeException("Failed to atomically promote temporary mail backup to [{$finalPath}]");
            }

            $durationSeconds = round(microtime(true) - $startTime, 3);

            $manifest = [
                'backup_id'          => $backupId,
                'timestamp'          => Carbon::now('UTC')->toIso8601String(),
                'type'               => 'mail_storage',
                'source_path'        => $source,
                'filename'           => $tarGzFilename,
                'path'               => $finalPath,
                'size_bytes'         => $sizeBytes,
                'uncompressed_bytes' => $uncompressedBytes,
                'file_count'         => $fileCount,
                'sha256'             => $sha256,
                'duration_seconds'   => $durationSeconds,
                'status'             => 'SUCCESS',
            ];

            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->log('info', "Mail storage backup completed successfully: {$tarGzFilename}", [
                'backup_id'        => $backupId,
                'size_bytes'       => $sizeBytes,
                'file_count'       => $fileCount,
                'duration_seconds' => $durationSeconds,
            ]);

            return $manifest;

        } catch (Throwable $e) {
            if (file_exists($tempTarPath)) {
                @unlink($tempTarPath);
            }
            if (file_exists($tempTarGzPath)) {
                @unlink($tempTarGzPath);
            }

            $this->log('error', "Mail storage backup failed: {$e->getMessage()}", [
                'backup_id' => $backupId,
                'exception' => get_class($e),
            ]);

            throw $e;
        }
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('backup')->log($level, "[MAIL-BACKUP] {$message}", $context);
        } catch (Throwable) {
            Log::log($level, "[MAIL-BACKUP] {$message}", $context);
        }
    }
}
