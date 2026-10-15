<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupEncryptionService;
use Illuminate\Console\Command;
use Throwable;

class BackupDecryptCommand extends Command
{
    protected $signature = 'backup:decrypt 
                            {archive : Path to encrypted backup archive (.sql.gz.enc or .tar.gz.enc)} 
                            {target? : Optional target path for decrypted output file} 
                            {--key= : Custom decryption passphrase} 
                            {--stdout : Stream decrypted plaintext directly to standard output}';

    protected $description = 'Decrypt an authenticated AEAD or legacy encrypted backup archive.';

    public function handle(BackupEncryptionService $encryptionService): int
    {
        $archivePath = $this->argument('archive');
        $targetPath = $this->argument('target');
        $customKey = $this->option('key');
        $isStdout = (bool) $this->option('stdout');

        if (!file_exists($archivePath)) {
            $this->logError("Encrypted backup archive not found: [{$archivePath}]", $isStdout);
            return Command::FAILURE;
        }

        try {
            $key = $customKey ?? $encryptionService->getKey();
            if (empty($key)) {
                $this->logError("Decryption failed: No encryption key provided or configured.", $isStdout);
                return Command::FAILURE;
            }

            if ($isStdout) {
                // Stream directly to output stream in binary mode
                $outHandle = fopen('php://output', 'wb');
                if (!$outHandle) {
                    $outHandle = fopen('php://stdout', 'wb');
                }
                if (!$outHandle) {
                    $this->logError("Failed to open output stream for writing.", $isStdout);
                    return Command::FAILURE;
                }

                $encryptionService->decryptToStream($archivePath, $outHandle, $key);
                fclose($outHandle);
                return Command::SUCCESS;
            }

            // File-based target decryption
            if (empty($targetPath)) {
                if (str_ends_with($archivePath, '.enc')) {
                    $targetPath = substr($archivePath, 0, -4);
                } else {
                    $targetPath = $archivePath . '.decrypted';
                }
            }

            $this->info("Decrypting backup archive: {$archivePath}");
            $this->line("Target destination:        {$targetPath}");

            $result = $encryptionService->decryptFile($archivePath, $targetPath, $key);

            $this->table(
                ['Attribute', 'Value'],
                [
                    ['Source Archive', basename($archivePath)],
                    ['Decrypted Target', $targetPath],
                    ['Envelope Format', $result['format']],
                    ['Authenticated AEAD', $result['authenticated'] ? 'YES' : 'NO'],
                    ['Decrypted Size', number_format($result['size_bytes']) . ' bytes'],
                    ['Decrypted SHA-256', $result['sha256']],
                ]
            );

            if (!empty($result['warning'])) {
                $this->warn("SECURITY WARNING: {$result['warning']}");
            }

            $this->info("Decryption completed successfully.");
            return Command::SUCCESS;

        } catch (Throwable $e) {
            $this->logError("Decryption FAILED: " . $e->getMessage(), $isStdout);
            return Command::FAILURE;
        }
    }

    protected function logError(string $message, bool $isStdout): void
    {
        if ($isStdout) {
            file_put_contents('php://stderr', "[ERROR] {$message}" . PHP_EOL);
        } else {
            $this->error($message);
        }
    }
}
