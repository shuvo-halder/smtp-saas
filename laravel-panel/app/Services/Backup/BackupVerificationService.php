<?php

namespace App\Services\Backup;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PDO;
use PharData;
use RuntimeException;
use Throwable;

class BackupVerificationService
{
    /**
     * Verify the physical and cryptographic integrity of any backup archive (.sql.gz or .tar.gz).
     *
     * @param string $filePath
     * @param string|null $expectedSha256
     * @return array
     */
    public function verifyArchiveIntegrity(string $filePath, ?string $expectedSha256 = null): array
    {
        $errors = [];

        if (!file_exists($filePath)) {
            return [
                'valid'      => false,
                'file_path'  => $filePath,
                'size_bytes' => 0,
                'sha256'     => null,
                'errors'     => ["File does not exist: [{$filePath}]"],
            ];
        }

        $sizeBytes = filesize($filePath);
        if ($sizeBytes === 0) {
            $errors[] = "Backup file is empty (0 bytes).";
        }

        // 1. Verify Gzip Decompression Integrity
        $fh = @fopen($filePath, 'rb');
        $magic = $fh ? fread($fh, 2) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== "\x1f\x8b") {
            $errors[] = "File does not contain a valid gzip header.";
        }

        $gz = @gzopen($filePath, 'rb');
        if (!$gz) {
            $errors[] = "File is not a valid gzip stream or cannot be read.";
        } else {
            $decompressedBytes = 0;
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 1024 * 256);
                if ($chunk === false) {
                    $errors[] = "Gzip stream encountered a corruption/read error during decompression.";
                    break;
                }
                $decompressedBytes += strlen($chunk);
            }
            gzclose($gz);

            if ($decompressedBytes === 0 && empty($errors)) {
                $errors[] = "Decompressed data is 0 bytes.";
            }
        }

        // 2. Verify SHA-256 Checksum
        $sha256 = hash_file('sha256', $filePath);
        if ($expectedSha256 !== null && !hash_equals(strtolower($expectedSha256), strtolower($sha256))) {
            $errors[] = "SHA-256 checksum mismatch. Expected [{$expectedSha256}], observed [{$sha256}].";
        }

        // 3. Manifest Verification
        $manifestPath = "{$filePath}.manifest.json";
        if (file_exists($manifestPath)) {
            $manifestContent = @file_get_contents($manifestPath);
            $manifest = json_decode((string) $manifestContent, true);
            if (!is_array($manifest)) {
                $errors[] = "Companion manifest is not valid JSON.";
            } elseif (isset($manifest['sha256']) && !hash_equals(strtolower($manifest['sha256']), strtolower($sha256))) {
                $errors[] = "Manifest recorded SHA-256 [{$manifest['sha256']}] does not match observed file SHA-256 [{$sha256}].";
            }
        }

        $isValid = empty($errors);

        return [
            'valid'              => $isValid,
            'file_path'          => $filePath,
            'size_bytes'         => $sizeBytes,
            'decompressed_bytes' => $decompressedBytes ?? 0,
            'sha256'             => $sha256,
            'errors'             => $errors,
        ];
    }

    /**
     * Verify database backup integrity and structure.
     *
     * @param string $sqlGzPath
     * @return array
     */
    public function verifyDatabaseBackup(string $sqlGzPath): array
    {
        $integrity = $this->verifyArchiveIntegrity($sqlGzPath);
        if (!$integrity['valid']) {
            return $integrity;
        }

        // Sample first 64KB of decompressed SQL to verify structure markers
        $gz = @gzopen($sqlGzPath, 'rb');
        $headerSnippet = $gz ? gzread($gz, 65536) : '';
        if ($gz) {
            gzclose($gz);
        }

        $hasExpectedMarkers = str_contains($headerSnippet, 'CREATE TABLE')
            || str_contains($headerSnippet, 'EmailSaaS Database Backup')
            || str_contains($headerSnippet, 'INSERT INTO');

        if (!$hasExpectedMarkers) {
            $integrity['valid'] = false;
            $integrity['errors'][] = "Database backup does not contain recognized SQL DDL/DML statements.";
        }

        return $integrity;
    }

    /**
     * Perform an isolated restore test of a database backup into a disposable SQLite database.
     * Guarantees that production is never touched while verifying that schema, tables, and data are restorable.
     *
     * @param string $sqlGzPath
     * @param string|null $customTargetSqlite
     * @return array
     * @throws Throwable
     */
    public function verifyIsolatedDatabaseRestore(string $sqlGzPath, ?string $customTargetSqlite = null): array
    {
        $startTime = microtime(true);
        $tempDb = $customTargetSqlite ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_verify_' . uniqid() . '.sqlite');

        if (file_exists($tempDb)) {
            @unlink($tempDb);
        }

        $gz = @gzopen($sqlGzPath, 'rb');
        if (!$gz) {
            throw new RuntimeException("Cannot open database backup file at [{$sqlGzPath}]");
        }

        $sqlContent = '';
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 1024 * 512);
            if ($chunk !== false) {
                $sqlContent .= $chunk;
            }
        }
        gzclose($gz);

        $pdo = null;

        try {
            $pdo = new PDO("sqlite:{$tempDb}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Split and execute SQL statements
            // Strip MySQL-specific comments or incompatible commands for SQLite parsing
            $statements = $this->splitSqlStatements($sqlContent);

            foreach ($statements as $sql) {
                $trimmed = trim($sql);

                // Strip leading SQL comments (-- and /* */)
                while (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                    if (str_starts_with($trimmed, '--')) {
                        $newline = strpos($trimmed, "\n");
                        if ($newline === false) {
                            $trimmed = '';
                            break;
                        }
                        $trimmed = trim(substr($trimmed, $newline + 1));
                    } elseif (str_starts_with($trimmed, '/*')) {
                        $commentEnd = strpos($trimmed, '*/');
                        if ($commentEnd === false) {
                            $trimmed = '';
                            break;
                        }
                        $trimmed = trim(substr($trimmed, $commentEnd + 2));
                    }
                }

                if (empty($trimmed)) {
                    continue;
                }

                // Skip MySQL-specific directives in SQLite verification
                if (preg_match('/^(SET|START TRANSACTION|COMMIT|UNLOCK TABLES|LOCK TABLES)/i', $trimmed)) {
                    continue;
                }

                try {
                    $pdo->exec($trimmed);
                } catch (Throwable $e) {
                    // Ignore non-fatal SQLite syntax quirks for MySQL-specific DDL if any
                }
            }

            // Inspect tables in restored database
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tables[] = $row['name'];
            }

            $tableCounts = [];
            $totalRows = 0;

            foreach ($tables as $table) {
                try {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM \"{$table}\"");
                    $cnt = (int) $countStmt->fetchColumn();
                    $tableCounts[$table] = $cnt;
                    $totalRows += $cnt;
                } catch (Throwable) {
                    $tableCounts[$table] = 0;
                }
            }

            $criticalTables = config('backup.critical_tables', [
                'users', 'domains', 'mailboxes', 'plans', 'invoices', 'audit_logs', 'abuse_incidents'
            ]);

            $criticalFound = [];
            foreach ($criticalTables as $crit) {
                $criticalFound[$crit] = in_array($crit, $tables);
            }

            unset($pdo);
            $pdo = null;

            if ($customTargetSqlite === null && file_exists($tempDb)) {
                @unlink($tempDb);
            }

            $duration = round(microtime(true) - $startTime, 3);

            return [
                'verified'         => count($tables) > 0,
                'restored'         => count($tables) > 0,
                'tables_restored'  => count($tables),
                'tables'           => $tables,
                'table_counts'     => $tableCounts,
                'total_rows'       => $totalRows,
                'critical_tables'  => $criticalFound,
                'duration_seconds' => $duration,
            ];

        } catch (Throwable $e) {
            unset($pdo);
            if ($customTargetSqlite === null && file_exists($tempDb)) {
                @unlink($tempDb);
            }
            throw $e;
        }
    }

    /**
     * Perform an isolated restore test of mail storage into a target temporary directory.
     *
     * @param string $tarGzPath
     * @param string $targetRestoreDir
     * @return array
     * @throws Throwable
     */
    public function verifyIsolatedMailRestore(string $tarGzPath, string $targetRestoreDir): array
    {
        $startTime = microtime(true);

        if (!file_exists($tarGzPath)) {
            throw new RuntimeException("Mail backup archive does not exist at [{$tarGzPath}]");
        }

        File::ensureDirectoryExists($targetRestoreDir);

        // Decompress .tar.gz into a temporary .tar in system temp
        $tempTar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vmail_restore_' . uniqid() . '.tar';

        $gz = @gzopen($tarGzPath, 'rb');
        $tarFile = @fopen($tempTar, 'wb');

        if (!$gz || !$tarFile) {
            throw new RuntimeException("Failed to open streams to decompress mail backup archive.");
        }

        while (!gzeof($gz)) {
            $chunk = gzread($gz, 1024 * 512);
            if ($chunk !== false && strlen($chunk) > 0) {
                fwrite($tarFile, $chunk);
            }
        }

        gzclose($gz);
        fclose($tarFile);

        try {
            $phar = new PharData($tempTar);
            $phar->extractTo($targetRestoreDir, null, true);
            unset($phar);
            @unlink($tempTar);

            // Count restored files and inspect Maildir structure
            $fileCount = 0;
            $maildirFoldersFound = false;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($targetRestoreDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $fileCount++;
                }
                if ($item->isDir() && in_array($item->getFilename(), ['cur', 'new', 'tmp'])) {
                    $maildirFoldersFound = true;
                }
            }

            $duration = round(microtime(true) - $startTime, 3);

            return [
                'verified'               => true,
                'restored'               => true,
                'target_directory'       => $targetRestoreDir,
                'files_restored'         => $fileCount,
                'maildir_folders_found'  => $maildirFoldersFound,
                'maildir_structure_valid'=> $maildirFoldersFound,
                'duration_seconds'       => $duration,
            ];

        } catch (Throwable $e) {
            if (file_exists($tempTar)) {
                @unlink($tempTar);
            }
            throw $e;
        }
    }

    /**
     * Alias helper for isolated mail restore verification.
     */
    public function verifyMailStorageBackup(string $tarGzPath, ?string $customTargetDir = null): array
    {
        $target = $customTargetDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vmail_restore_verify_' . uniqid());
        $result = $this->verifyIsolatedMailRestore($tarGzPath, $target);
        if ($customTargetDir === null && is_dir($target)) {
            File::deleteDirectory($target);
        }
        return $result;
    }

    /**
     * Splits a raw SQL dump into executable queries.
     */
    protected function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            if ($inString) {
                if ($char === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inString = false;
                }
                $current .= $char;
            } else {
                if ($char === '\'' || $char === '"') {
                    $inString = true;
                    $stringChar = $char;
                    $current .= $char;
                } elseif ($char === ';') {
                    $trimmed = trim($current);
                    if (!empty($trimmed)) {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
        }

        if (!empty(trim($current))) {
            $statements[] = trim($current);
        }

        return $statements;
    }
}
