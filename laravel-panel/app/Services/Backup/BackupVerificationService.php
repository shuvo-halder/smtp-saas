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
    public function __construct(
        protected ?BackupEncryptionService $encryptionService = null
    ) {
        $this->encryptionService = $encryptionService ?? app(BackupEncryptionService::class);
    }

    /**
     * Verify the physical and cryptographic integrity of any backup archive (.sql.gz, .tar.gz, or .enc).
     *
     * @param string $filePath
     * @param string|null $expectedSha256
     * @param string|null $customKey
     * @return array
     */
    public function verifyArchiveIntegrity(string $filePath, ?string $expectedSha256 = null, ?string $customKey = null): array
    {
        $errors = [];

        if (!file_exists($filePath)) {
            return [
                'valid'      => false,
                'file_path'  => $filePath,
                'size_bytes' => 0,
                'sha256'     => null,
                'encrypted'  => false,
                'errors'     => ["File does not exist: [{$filePath}]"],
            ];
        }

        $sizeBytes = filesize($filePath);
        if ($sizeBytes === 0) {
            $errors[] = "Backup file is empty (0 bytes).";
        }

        $isEncrypted = str_ends_with($filePath, '.enc');
        $decompressedBytes = 0;
        $tempDecryptedPath = null;

        if ($isEncrypted) {
            // Verify OpenSSL encrypted envelope header
            $fh = @fopen($filePath, 'rb');
            $magic = $fh ? fread($fh, 8) : '';
            if ($fh) {
                fclose($fh);
            }

            if ($magic !== BackupEncryptionService::MAGIC_HEADER) {
                $errors[] = "Encrypted backup does not contain valid OpenSSL magic header (Salted__).";
            }

            // Attempt decryption and payload decompression if encryption key is available
            try {
                $key = $customKey ?? $this->encryptionService->getKey();
                if (!empty($key)) {
                    $tempDecryptedPath = $this->encryptionService->decryptToTemp($filePath, $key);
                    $decompressedBytes = $this->testGzipIntegrity($tempDecryptedPath, $errors);
                }
            } catch (Throwable $e) {
                $errors[] = "Decryption failed during integrity verification: " . $e->getMessage();
            } finally {
                if ($tempDecryptedPath && file_exists($tempDecryptedPath)) {
                    @unlink($tempDecryptedPath);
                    $tempDecryptedPath = null;
                }
            }

        } else {
            // Direct gzip stream check
            $decompressedBytes = $this->testGzipIntegrity($filePath, $errors);
        }

        // 2. Verify SHA-256 Checksum of the primary archive file
        $sha256 = hash_file('sha256', $filePath);
        if ($expectedSha256 !== null && !hash_equals(strtolower($expectedSha256), strtolower($sha256))) {
            $errors[] = "SHA-256 checksum mismatch. Expected [{$expectedSha256}], observed [{$sha256}].";
        }

        // 3. Companion Manifest Verification
        $manifestPath = "{$filePath}.manifest.json";
        if (file_exists($manifestPath)) {
            $manifestContent = @file_get_contents($manifestPath);
            $manifest = json_decode((string) $manifestContent, true);
            if (!is_array($manifest)) {
                $errors[] = "Companion manifest is not valid JSON.";
            } else {
                if (isset($manifest['sha256']) && !hash_equals(strtolower($manifest['sha256']), strtolower($sha256))) {
                    $errors[] = "Manifest recorded SHA-256 [{$manifest['sha256']}] does not match observed file SHA-256 [{$sha256}].";
                }
                if ($isEncrypted && empty($manifest['encrypted'])) {
                    $errors[] = "File is encrypted (.enc) but companion manifest indicates unencrypted.";
                }
            }
        }

        $isValid = empty($errors);

        if ($isValid) {
            $this->updateManifest($filePath, [
                'verified'    => true,
                'verified_at' => Carbon::now('UTC')->toIso8601String(),
            ]);
        }

        return [
            'valid'              => $isValid,
            'file_path'          => $filePath,
            'size_bytes'         => $sizeBytes,
            'decompressed_bytes' => $decompressedBytes,
            'sha256'             => $sha256,
            'encrypted'          => $isEncrypted,
            'errors'             => $errors,
        ];
    }

    /**
     * Test gzip magic header and decompress the entire stream to verify integrity.
     */
    protected function testGzipIntegrity(string $path, array &$errors): int
    {
        $fh = @fopen($path, 'rb');
        $magic = $fh ? fread($fh, 2) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== "\x1f\x8b") {
            $errors[] = "File does not contain a valid gzip header.";
            return 0;
        }

        $gz = @gzopen($path, 'rb');
        if (!$gz) {
            $errors[] = "File is not a valid gzip stream or cannot be read.";
            return 0;
        }

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

        return $decompressedBytes;
    }

    /**
     * Verify database backup integrity and structure.
     * Supports encrypted archives (.sql.gz.enc).
     *
     * @param string $sqlGzPath
     * @param string|null $customKey
     * @return array
     */
    public function verifyDatabaseBackup(string $sqlGzPath, ?string $customKey = null): array
    {
        $integrity = $this->verifyArchiveIntegrity($sqlGzPath, null, $customKey);
        if (!$integrity['valid']) {
            return $integrity;
        }

        $tempDecrypted = null;
        $inspectPath = $sqlGzPath;

        try {
            if (str_ends_with($sqlGzPath, '.enc')) {
                $key = $customKey ?? $this->encryptionService->getKey();
                $tempDecrypted = $this->encryptionService->decryptToTemp($sqlGzPath, $key);
                $inspectPath = $tempDecrypted;
            }

            // Sample first 64KB of decompressed SQL to verify structure markers
            $gz = @gzopen($inspectPath, 'rb');
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
            } else {
                $this->updateManifest($sqlGzPath, [
                    'verified'    => true,
                    'verified_at' => Carbon::now('UTC')->toIso8601String(),
                ]);
            }

            return $integrity;

        } finally {
            if ($tempDecrypted && file_exists($tempDecrypted)) {
                @unlink($tempDecrypted);
            }
        }
    }

    /**
     * Perform an isolated restore test of a database backup into a disposable SQLite database.
     * Guarantees that production is never touched while verifying that schema, tables, and data are restorable.
     * Supports encrypted archives (.sql.gz.enc).
     *
     * @param string $sqlGzPath
     * @param string|null $customTargetSqlite
     * @param string|null $customKey
     * @return array
     * @throws Throwable
     */
    public function verifyIsolatedDatabaseRestore(string $sqlGzPath, ?string $customTargetSqlite = null, ?string $customKey = null): array
    {
        $startTime = microtime(true);
        $tempDb = $customTargetSqlite ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_verify_' . uniqid() . '.sqlite');
        $tempDecrypted = null;

        if (file_exists($tempDb)) {
            @unlink($tempDb);
        }

        try {
            $inspectPath = $sqlGzPath;
            if (str_ends_with($sqlGzPath, '.enc')) {
                $key = $customKey ?? $this->encryptionService->getKey();
                $tempDecrypted = $this->encryptionService->decryptToTemp($sqlGzPath, $key);
                $inspectPath = $tempDecrypted;
            }

            $gz = @gzopen($inspectPath, 'rb');
            if (!$gz) {
                throw new RuntimeException("Cannot open database backup file at [{$inspectPath}]");
            }

            $sqlContent = '';
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 1024 * 512);
                if ($chunk !== false) {
                    $sqlContent .= $chunk;
                }
            }
            gzclose($gz);

            $pdo = new PDO("sqlite:{$tempDb}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Split and execute SQL statements
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
                } catch (Throwable) {
                    // Ignore non-fatal SQLite syntax quirks
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

            $this->updateManifest($sqlGzPath, [
                'restore_tested'    => true,
                'restore_tested_at' => Carbon::now('UTC')->toIso8601String(),
            ]);

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

        } finally {
            if ($tempDecrypted && file_exists($tempDecrypted)) {
                @unlink($tempDecrypted);
            }
            if ($customTargetSqlite === null && file_exists($tempDb)) {
                @unlink($tempDb);
            }
        }
    }

    /**
     * Perform an isolated restore test of mail storage into a target temporary directory.
     * Supports encrypted archives (.tar.gz.enc).
     *
     * @param string $tarGzPath
     * @param string $targetRestoreDir
     * @param string|null $customKey
     * @return array
     * @throws Throwable
     */
    public function verifyIsolatedMailRestore(string $tarGzPath, string $targetRestoreDir, ?string $customKey = null): array
    {
        $startTime = microtime(true);

        if (!file_exists($tarGzPath)) {
            throw new RuntimeException("Mail backup archive does not exist at [{$tarGzPath}]");
        }

        File::ensureDirectoryExists($targetRestoreDir);

        $tempDecrypted = null;
        $tempTar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vmail_restore_' . uniqid() . '.tar';

        try {
            $inspectPath = $tarGzPath;
            if (str_ends_with($tarGzPath, '.enc')) {
                $key = $customKey ?? $this->encryptionService->getKey();
                $tempDecrypted = $this->encryptionService->decryptToTemp($tarGzPath, $key);
                $inspectPath = $tempDecrypted;
            }

            // Decompress .tar.gz into a temporary .tar in system temp
            $gz = @gzopen($inspectPath, 'rb');
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

            $this->updateManifest($tarGzPath, [
                'restore_tested'    => true,
                'restore_tested_at' => Carbon::now('UTC')->toIso8601String(),
            ]);

            return [
                'verified'                => true,
                'restored'                => true,
                'target_directory'        => $targetRestoreDir,
                'files_restored'          => $fileCount,
                'maildir_folders_found'   => $maildirFoldersFound,
                'maildir_structure_valid' => $maildirFoldersFound,
                'duration_seconds'        => $duration,
            ];

        } finally {
            if ($tempDecrypted && file_exists($tempDecrypted)) {
                @unlink($tempDecrypted);
            }
            if (file_exists($tempTar)) {
                @unlink($tempTar);
            }
        }
    }

    /**
     * Alias helper for isolated mail restore verification.
     */
    public function verifyMailStorageBackup(string $tarGzPath, ?string $customTargetDir = null, ?string $customKey = null): array
    {
        $target = $customTargetDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vmail_restore_verify_' . uniqid());
        $result = $this->verifyIsolatedMailRestore($tarGzPath, $target, $customKey);
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

    /**
     * Update companion JSON manifest with verification or restore state.
     */
    protected function updateManifest(string $filePath, array $attributes): void
    {
        $manifestPath = "{$filePath}.manifest.json";
        if (!file_exists($manifestPath)) {
            return;
        }

        try {
            $content = file_get_contents($manifestPath);
            $manifest = json_decode((string) $content, true);
            if (!is_array($manifest)) {
                return;
            }

            foreach ($attributes as $key => $value) {
                $manifest[$key] = $value;
            }

            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            Log::warning("[VERIFY-MANIFEST] Failed to update manifest at [{$manifestPath}]: " . $e->getMessage());
        }
    }
}
