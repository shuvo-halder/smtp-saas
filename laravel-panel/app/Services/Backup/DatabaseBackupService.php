<?php

namespace App\Services\Backup;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

class DatabaseBackupService
{
    public function __construct(
        protected ?string $storagePath = null
    ) {
        $this->storagePath = $storagePath ?? (config('backup.storage_path') . '/db');
    }

    /**
     * Generate a transaction-safe compressed database backup with SHA-256 manifest.
     *
     * @param string|null $destinationDir
     * @param string|null $connectionName
     * @return array
     * @throws Throwable
     */
    public function backup(?string $destinationDir = null, ?string $connectionName = null): array
    {
        $targetDir = $destinationDir ?? $this->storagePath;
        File::ensureDirectoryExists($targetDir);

        $connection = $connectionName ?? config('database.default');
        $timestamp = Carbon::now('UTC')->format('Ymd_His');
        $backupId = (string) Str::uuid();

        $filename = "db_backup_{$timestamp}.sql.gz";
        $finalPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($finalPath)) {
            $filename = "db_backup_{$timestamp}_" . substr($backupId, 0, 8) . ".sql.gz";
            $finalPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        }

        $tempPath = $targetDir . DIRECTORY_SEPARATOR . "{$filename}.tmp";
        $manifestPath = $targetDir . DIRECTORY_SEPARATOR . "{$filename}.manifest.json";

        $startTime = microtime(true);
        $gz = null;

        try {
            // Open temp gzip stream
            $gz = @gzopen($tempPath, 'wb9');
            if (!$gz) {
                throw new RuntimeException("Failed to open temporary backup stream at [{$tempPath}]");
            }

            $pdo = DB::connection($connection)->getPdo();
            $driver = DB::connection($connection)->getDriverName();
            $dbName = config("database.connections.{$connection}.database", 'smtp');

            $dumpHeader = "-- EmailSaaS Database Backup\n"
                . "-- Generated: " . Carbon::now('UTC')->toIso8601String() . "\n"
                . "-- Database: {$dbName} (Driver: {$driver})\n"
                . "-- Backup ID: {$backupId}\n\n";

            gzwrite($gz, $dumpHeader);

            if ($driver === 'mysql') {
                gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSTART TRANSACTION;\n\n");
            }

            $tables = $this->getTables($pdo, $driver);
            $tablesCount = count($tables);

            foreach ($tables as $table) {
                // Skip SQLite internal system tables
                if ($driver === 'sqlite' && str_starts_with($table, 'sqlite_')) {
                    continue;
                }

                // Dump schema
                $schemaSql = $this->getTableSchema($pdo, $driver, $table);
                gzwrite($gz, "-- Structure for table: {$table}\n{$schemaSql}\n\n");

                // Dump data in chunks
                gzwrite($gz, "-- Data for table: {$table}\n");
                $this->dumpTableData($pdo, $driver, $table, $gz);
                gzwrite($gz, "\n\n");
            }

            if ($driver === 'mysql') {
                gzwrite($gz, "COMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\n");
            }

            gzclose($gz);
            $gz = null;

            // Integrity verification of the written temp file
            if (!file_exists($tempPath) || filesize($tempPath) === 0) {
                throw new RuntimeException("Generated temporary backup file is empty or missing: [{$tempPath}]");
            }

            $sha256 = hash_file('sha256', $tempPath);
            $sizeBytes = filesize($tempPath);

            // Atomic promotion: rename temp file to final filename
            if (!rename($tempPath, $finalPath)) {
                throw new RuntimeException("Failed to atomically promote temporary backup file to [{$finalPath}]");
            }

            $durationSeconds = round(microtime(true) - $startTime, 3);

            // Companion Manifest
            $manifest = [
                'backup_id'        => $backupId,
                'timestamp'        => Carbon::now('UTC')->toIso8601String(),
                'type'             => 'database',
                'driver'           => $driver,
                'database_name'    => $dbName,
                'connection'       => $connection,
                'filename'         => $filename,
                'path'             => $finalPath,
                'size_bytes'       => $sizeBytes,
                'sha256'           => $sha256,
                'tables_count'     => $tablesCount,
                'tables'           => $tables,
                'duration_seconds' => $durationSeconds,
                'status'           => 'SUCCESS',
            ];

            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->log('info', "Database backup completed successfully: {$filename}", [
                'backup_id'        => $backupId,
                'size_bytes'       => $sizeBytes,
                'tables'           => $tablesCount,
                'duration_seconds' => $durationSeconds,
            ]);

            return $manifest;

        } catch (Throwable $e) {
            if ($gz) {
                @gzclose($gz);
            }
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            $this->log('error', "Database backup failed: {$e->getMessage()}", [
                'backup_id' => $backupId,
                'exception' => get_class($e),
            ]);

            throw $e;
        }
    }

    /**
     * Get all tables for the given database connection.
     */
    protected function getTables(PDO $pdo, string $driver): array
    {
        $tables = [];

        if ($driver === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tables[] = $row['name'];
            }
        } else {
            // MySQL / MariaDB
            $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
        }

        return $tables;
    }

    /**
     * Get CREATE TABLE statement for table.
     */
    protected function getTableSchema(PDO $pdo, string $driver, string $table): string
    {
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
            $stmt->execute([$table]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($row['sql'] ?? '') . ";";
        }

        // MySQL / MariaDB
        $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return "DROP TABLE IF EXISTS `{$table}`;\n" . ($row[1] ?? '') . ";";
    }

    /**
     * Dump table data as SQL INSERT statements.
     */
    protected function dumpTableData(PDO $pdo, string $driver, string $table, $gz): void
    {
        $quotedTable = $driver === 'sqlite' ? "\"{$table}\"" : "`{$table}`";
        $stmt = $pdo->query("SELECT * FROM {$quotedTable}");

        $rows = [];
        $columns = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($columns)) {
                $columns = array_keys($row);
            }

            $escapedValues = array_map(function ($val) use ($pdo) {
                if ($val === null) {
                    return 'NULL';
                }
                return $pdo->quote((string) $val);
            }, array_values($row));

            $rows[] = '(' . implode(', ', $escapedValues) . ')';

            if (count($rows) >= 200) {
                $this->writeInsertBatch($gz, $driver, $table, $columns, $rows);
                $rows = [];
            }
        }

        if (!empty($rows)) {
            $this->writeInsertBatch($gz, $driver, $table, $columns, $rows);
        }
    }

    protected function writeInsertBatch($gz, string $driver, string $table, array $columns, array $rows): void
    {
        $quotedTable = $driver === 'sqlite' ? "\"{$table}\"" : "`{$table}`";
        $colList = implode(', ', array_map(fn($c) => $driver === 'sqlite' ? "\"{$c}\"" : "`{$c}`", $columns));
        $valuesList = implode(",\n", $rows);

        $sql = "INSERT INTO {$quotedTable} ({$colList}) VALUES\n{$valuesList};\n";
        gzwrite($gz, $sql);
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('backup')->log($level, "[DATABASE-BACKUP] {$message}", $context);
        } catch (Throwable) {
            Log::log($level, "[DATABASE-BACKUP] {$message}", $context);
        }
    }
}
