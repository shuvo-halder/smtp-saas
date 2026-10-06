<?php

namespace App\Services\Backup;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class BackupOffsiteService
{
    public function __construct(
        protected ?BackupVerificationService $verificationService = null
    ) {
        $this->verificationService = $verificationService ?? app(BackupVerificationService::class);
    }

    /**
     * Check if offsite replication is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('backup.offsite.enabled', false);
    }

    /**
     * Synchronize a local backup archive and its manifest to offsite destination.
     * Invariants:
     * - Verifies local backup integrity before attempting transfer.
     * - Remote transfer failure NEVER modifies or deletes local backup.
     * - Sensitive credentials and SSH keys are redacted from logs and errors.
     *
     * @param string $filePath
     * @return array
     */
    public function syncBackup(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'status'  => 'NOT_FOUND',
                'error'   => "Local backup file not found: [{$filePath}]",
            ];
        }

        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'status'  => 'DISABLED',
                'notice'  => 'Offsite replication is disabled in configuration.',
            ];
        }

        // 1. Verify local backup integrity before attempting transfer
        $integrity = $this->verificationService->verifyArchiveIntegrity($filePath);
        if (!$integrity['valid']) {
            $errorMsg = 'Local backup integrity verification failed prior to offsite transfer: ' . implode(', ', $integrity['errors']);
            $this->log('error', $errorMsg, ['file' => basename($filePath)]);
            $this->updateManifest($filePath, [
                'offsite_copied' => false,
                'offsite_status' => 'FAILED_LOCAL_INTEGRITY',
            ]);

            return [
                'success' => false,
                'status'  => 'FAILED_LOCAL_INTEGRITY',
                'error'   => $errorMsg,
            ];
        }

        $transport = config('backup.offsite.transport', 'rsync');
        $startTime = microtime(true);

        try {
            $this->executeTransport($transport, $filePath);

            // Also synchronize companion manifest if it exists
            $manifestPath = "{$filePath}.manifest.json";
            if (file_exists($manifestPath)) {
                $this->executeTransport($transport, $manifestPath);
            }

            $duration = round(microtime(true) - $startTime, 3);

            $this->updateManifest($filePath, [
                'offsite_copied'    => true,
                'offsite_status'    => 'COPIED',
                'offsite_copied_at' => Carbon::now('UTC')->toIso8601String(),
                'offsite_transport' => $transport,
            ]);

            $this->log('info', "Successfully synchronized backup offsite via [{$transport}]: " . basename($filePath), [
                'file'             => basename($filePath),
                'transport'        => $transport,
                'duration_seconds' => $duration,
            ]);

            return [
                'success'          => true,
                'status'           => 'COPIED',
                'transport'        => $transport,
                'file'             => basename($filePath),
                'duration_seconds' => $duration,
            ];

        } catch (Throwable $e) {
            $redactedMessage = $this->redactSensitiveString($e->getMessage());

            $this->updateManifest($filePath, [
                'offsite_copied' => false,
                'offsite_status' => 'FAILED',
                'offsite_error'  => $redactedMessage,
            ]);

            $this->log('error', "Offsite synchronization failed (local backup preserved): {$redactedMessage}", [
                'file'      => basename($filePath),
                'transport' => $transport,
            ]);

            return [
                'success' => false,
                'status'  => 'FAILED',
                'error'   => $redactedMessage,
            ];
        }
    }

    /**
     * Execute transport according to configured provider.
     */
    protected function executeTransport(string $transport, string $localFile): void
    {
        $host = config('backup.offsite.host');
        $port = (int) config('backup.offsite.port', 22);
        $user = config('backup.offsite.user', 'backup-operator');
        $remotePath = config('backup.offsite.path', '/var/backups/remote-mailsaas');
        $sshKey = config('backup.offsite.ssh_key');
        $timeout = (int) config('backup.offsite.timeout', 300);

        switch ($transport) {
            case 'local':
                // Synchronize to another locally mounted path or network share
                File::ensureDirectoryExists($remotePath);
                $destination = $remotePath . DIRECTORY_SEPARATOR . basename($localFile);
                if (!@copy($localFile, $destination)) {
                    throw new RuntimeException("Failed to copy file to local offsite destination [{$destination}].");
                }
                break;

            case 'rsync':
                if (empty($host)) {
                    throw new RuntimeException("Offsite host is required for rsync transport.");
                }
                $sshOpt = "-p {$port}";
                if (!empty($sshKey)) {
                    $sshOpt .= " -i " . escapeshellarg($sshKey);
                }

                $cmd = sprintf(
                    'rsync -avz -e %s %s %s@%s:%s',
                    escapeshellarg("ssh {$sshOpt} -o StrictHostKeyChecking=accept-new"),
                    escapeshellarg($localFile),
                    escapeshellarg($user),
                    escapeshellarg($host),
                    escapeshellarg($remotePath . '/')
                );

                $this->runCommand($cmd, $timeout);
                break;

            case 'scp':
                if (empty($host)) {
                    throw new RuntimeException("Offsite host is required for scp transport.");
                }
                $sshKeyArg = !empty($sshKey) ? "-i " . escapeshellarg($sshKey) : "";
                $cmd = sprintf(
                    'scp -P %d %s %s %s@%s:%s',
                    $port,
                    $sshKeyArg,
                    escapeshellarg($localFile),
                    escapeshellarg($user),
                    escapeshellarg($host),
                    escapeshellarg($remotePath . '/' . basename($localFile))
                );

                $this->runCommand($cmd, $timeout);
                break;

            case 'custom':
                $customCmd = config('backup.offsite.command');
                if (empty($customCmd)) {
                    throw new RuntimeException("Custom command is not configured for custom offsite transport.");
                }
                $resolvedCmd = str_replace(
                    ['{FILE}', '{BASENAME}', '{REMOTE_PATH}'],
                    [escapeshellarg($localFile), escapeshellarg(basename($localFile)), escapeshellarg($remotePath)],
                    $customCmd
                );

                $this->runCommand($resolvedCmd, $timeout);
                break;

            default:
                throw new RuntimeException("Unsupported offsite transport: [{$transport}].");
        }
    }

    /**
     * Run command safely using Laravel Process facade or exec.
     */
    protected function runCommand(string $command, int $timeout): void
    {
        $result = Process::timeout($timeout)->run($command);

        if ($result->failed()) {
            throw new RuntimeException("Transport command failed with exit code [{$result->exitCode()}]: " . $result->error());
        }
    }

    /**
     * Update companion JSON manifest.
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
            Log::warning("[OFFSITE-MANIFEST] Failed to update manifest at [{$manifestPath}]: " . $e->getMessage());
        }
    }

    /**
     * Redact passwords and private keys from strings.
     */
    protected function redactSensitiveString(string $input): string
    {
        return preg_replace([
            '/-(pass|password)[:\s]+[^\s]+/i',
            '/Bearer\s+[A-Za-z0-9\-_\.]+/i',
            '/BEGIN (RSA|OPENSSH|EC) PRIVATE KEY.*?END \1 PRIVATE KEY/s',
        ], '[REDACTED]', $input) ?? $input;
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('backup')->log($level, "[OFFSITE] {$message}", $context);
        } catch (Throwable) {
            Log::log($level, "[OFFSITE] {$message}", $context);
        }
    }
}
