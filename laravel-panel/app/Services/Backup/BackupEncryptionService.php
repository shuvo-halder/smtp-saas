<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BackupEncryptionService
{
    public const AEAD_MAGIC_HEADER = 'EMAILSAAS_GCM_V1';
    public const LEGACY_MAGIC_HEADER = 'Salted__';
    public const MAGIC_HEADER = self::AEAD_MAGIC_HEADER;
    public const PBKDF2_ITERATIONS = 10000;
    public const CHUNK_SIZE = 65536; // 64 KB streaming buffer
    public const GCM_TAG_LENGTH = 16;
    public const GCM_NONCE_PREFIX_LENGTH = 8;

    /**
     * Check if backup encryption is enabled in configuration.
     */
    public function isEncryptionEnabled(): bool
    {
        return (bool) config('backup.encryption.enabled', false);
    }

    /**
     * Get configured cipher (defaults to aes-256-gcm).
     */
    public function getCipher(): string
    {
        return (string) config('backup.encryption.cipher', 'aes-256-gcm');
    }

    /**
     * Retrieve the encryption key from environment variable or key file.
     * Throws RuntimeException if encryption is enabled but no key is present.
     */
    public function getKey(): ?string
    {
        $key = config('backup.encryption.key');

        if (empty($key)) {
            $keyPath = config('backup.encryption.key_path');
            if (!empty($keyPath) && file_exists($keyPath)) {
                $key = trim((string) @file_get_contents($keyPath));
            }
        }

        if (empty($key) && $this->isEncryptionEnabled()) {
            throw new RuntimeException("Backup encryption is enabled, but neither BACKUP_ENCRYPTION_KEY nor BACKUP_ENCRYPTION_KEY_PATH contains a valid key.");
        }

        return !empty($key) ? (string) $key : null;
    }

    /**
     * Encrypt a plaintext file to an encrypted archive using Authenticated AEAD (AES-256-GCM)
     * with chunked streaming envelope (EMAILSAAS_GCM_V1) and constant memory footprint.
     *
     * Envelope layout:
     * - Header (40 bytes):
     *     - Magic Header: 'EMAILSAAS_GCM_V1' (16 bytes)
     *     - Salt: 16 cryptographically random bytes
     *     - Nonce Prefix: 8 cryptographically random bytes
     * - Chunk frames:
     *     - Chunk length: uint32 BE (4 bytes)
     *     - Terminal flag: uint8 (1 byte, 1 for last chunk, 0 otherwise)
     *     - GCM Tag: 16 bytes
     *     - Ciphertext: length bytes
     *
     * @param string $sourcePlainPath
     * @param string $targetEncPath
     * @param string|null $customKey
     * @return array
     * @throws Throwable
     */
    public function encryptFile(string $sourcePlainPath, string $targetEncPath, ?string $customKey = null): array
    {
        $passphrase = $customKey ?? $this->getKey();
        if (empty($passphrase)) {
            throw new RuntimeException("Encryption passphrase cannot be empty.");
        }

        if (!file_exists($sourcePlainPath) || !is_readable($sourcePlainPath)) {
            throw new RuntimeException("Source plaintext file does not exist or is not readable: [{$sourcePlainPath}]");
        }

        $sourceHandle = @fopen($sourcePlainPath, 'rb');
        if (!$sourceHandle) {
            throw new RuntimeException("Failed to open source plaintext file for reading: [{$sourcePlainPath}]");
        }

        $tempEncPath = "{$targetEncPath}.tmp";
        File::ensureDirectoryExists(dirname($tempEncPath));
        $targetHandle = @fopen($tempEncPath, 'wb');
        if (!$targetHandle) {
            fclose($sourceHandle);
            throw new RuntimeException("Failed to open target encryption file for writing: [{$tempEncPath}]");
        }

        try {
            $salt = random_bytes(16);
            $noncePrefix = random_bytes(self::GCM_NONCE_PREFIX_LENGTH); // 8 bytes

            // Derive 32-byte key using PBKDF2 with SHA-256
            $key = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ITERATIONS, 32, true);

            // Write 40-byte header
            fwrite($targetHandle, self::AEAD_MAGIC_HEADER . $salt . $noncePrefix);

            $chunkIndex = 0;
            $chunk = fread($sourceHandle, self::CHUNK_SIZE);

            if ($chunk === false || strlen($chunk) === 0) {
                // Empty source file: write single authenticated empty terminal chunk
                $nonce = $noncePrefix . pack('N', 0);
                $aad = self::AEAD_MAGIC_HEADER . pack('N', 0) . chr(1);
                $tag = '';
                $ct = openssl_encrypt('', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::GCM_TAG_LENGTH);
                if ($ct === false || strlen($tag) !== self::GCM_TAG_LENGTH) {
                    throw new RuntimeException("OpenSSL AEAD encryption error for empty payload.");
                }
                $frameHeader = pack('N', 0) . chr(1) . $tag;
                fwrite($targetHandle, $frameHeader);
            } else {
                while ($chunk !== false && strlen($chunk) > 0) {
                    $peek = fread($sourceHandle, self::CHUNK_SIZE);
                    $isLast = ($peek === false || strlen($peek) === 0);

                    $nonce = $noncePrefix . pack('N', $chunkIndex);
                    $aad = self::AEAD_MAGIC_HEADER . pack('N', $chunkIndex) . chr($isLast ? 1 : 0);

                    $tag = '';
                    $ct = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::GCM_TAG_LENGTH);
                    if ($ct === false || strlen($tag) !== self::GCM_TAG_LENGTH) {
                        throw new RuntimeException("OpenSSL AEAD encryption error: " . openssl_error_string());
                    }

                    $frameHeader = pack('N', strlen($ct)) . chr($isLast ? 1 : 0) . $tag;
                    fwrite($targetHandle, $frameHeader . $ct);

                    $chunk = $peek;
                    $chunkIndex++;
                }
            }

            fclose($sourceHandle);
            fclose($targetHandle);
            $sourceHandle = null;
            $targetHandle = null;

            if (!file_exists($tempEncPath) || filesize($tempEncPath) < 40) {
                throw new RuntimeException("Encrypted temporary file is invalid or too small: [{$tempEncPath}]");
            }

            // Atomic promotion
            if (!rename($tempEncPath, $targetEncPath)) {
                throw new RuntimeException("Failed to atomically promote encrypted file to [{$targetEncPath}]");
            }

            return [
                'encrypted'  => true,
                'cipher'     => 'aes-256-gcm',
                'format'     => 'AEAD_GCM_V1',
                'path'       => $targetEncPath,
                'size_bytes' => filesize($targetEncPath),
                'sha256'     => hash_file('sha256', $targetEncPath),
            ];

        } catch (Throwable $e) {
            if (is_resource($sourceHandle)) {
                fclose($sourceHandle);
            }
            if (is_resource($targetHandle)) {
                fclose($targetHandle);
            }
            if (file_exists($tempEncPath)) {
                @unlink($tempEncPath);
            }
            throw $e;
        }
    }

    /**
     * Decrypt an encrypted archive directly to an open output stream.
     * Automatically handles AEAD_GCM_V1 and isolates LEGACY_CBC.
     *
     * @param string $sourceEncPath
     * @param resource $outputStream
     * @param string|null $customKey
     * @return array
     * @throws Throwable
     */
    public function decryptToStream(string $sourceEncPath, $outputStream, ?string $customKey = null): array
    {
        $passphrase = $customKey ?? $this->getKey();
        if (empty($passphrase)) {
            throw new RuntimeException("Decryption passphrase cannot be empty.");
        }

        if (!file_exists($sourceEncPath) || !is_readable($sourceEncPath)) {
            throw new RuntimeException("Encrypted backup file does not exist or is not readable: [{$sourceEncPath}]");
        }

        $sourceHandle = @fopen($sourceEncPath, 'rb');
        if (!$sourceHandle) {
            throw new RuntimeException("Failed to open encrypted file: [{$sourceEncPath}]");
        }

        try {
            $header = fread($sourceHandle, 16);
            if ($header === self::AEAD_MAGIC_HEADER) {
                return $this->decryptAeadStream($sourceHandle, $outputStream, $passphrase);
            }

            if (substr($header, 0, 8) === self::LEGACY_MAGIC_HEADER) {
                $salt = substr($header, 8, 8);
                return $this->decryptLegacyCbcStream($sourceHandle, $outputStream, $passphrase, $salt);
            }

            throw new RuntimeException("File does not contain valid encrypted header (expected 'EMAILSAAS_GCM_V1' or legacy 'Salted__').");
        } finally {
            if (is_resource($sourceHandle)) {
                fclose($sourceHandle);
            }
        }
    }

    /**
     * Decrypt an encrypted archive back to a plaintext file.
     *
     * @param string $sourceEncPath
     * @param string $targetPlainPath
     * @param string|null $customKey
     * @return array
     * @throws Throwable
     */
    public function decryptFile(string $sourceEncPath, string $targetPlainPath, ?string $customKey = null): array
    {
        $tempPlainPath = "{$targetPlainPath}.tmp";
        File::ensureDirectoryExists(dirname($tempPlainPath));
        $targetHandle = @fopen($tempPlainPath, 'wb');
        if (!$targetHandle) {
            throw new RuntimeException("Failed to open target plaintext stream for writing: [{$tempPlainPath}]");
        }

        try {
            $info = $this->decryptToStream($sourceEncPath, $targetHandle, $customKey);
            fclose($targetHandle);
            $targetHandle = null;

            if (!rename($tempPlainPath, $targetPlainPath)) {
                throw new RuntimeException("Failed to promote decrypted file to [{$targetPlainPath}]");
            }

            return [
                'path'          => $targetPlainPath,
                'size_bytes'    => filesize($targetPlainPath),
                'sha256'        => hash_file('sha256', $targetPlainPath),
                'format'        => $info['format'],
                'authenticated' => $info['authenticated'],
                'warning'       => $info['warning'] ?? null,
            ];

        } catch (Throwable $e) {
            if (is_resource($targetHandle)) {
                fclose($targetHandle);
            }
            if (file_exists($tempPlainPath)) {
                @unlink($tempPlainPath);
            }
            throw $e;
        }
    }

    /**
     * Decrypt an AEAD chunked stream with full tag and integrity checks.
     */
    protected function decryptAeadStream($sourceHandle, $outputStream, string $passphrase): array
    {
        $salt = fread($sourceHandle, 16);
        if ($salt === false || strlen($salt) !== 16) {
            throw new RuntimeException("Corrupted AEAD encrypted backup: invalid salt length.");
        }

        $noncePrefix = fread($sourceHandle, self::GCM_NONCE_PREFIX_LENGTH); // 8 bytes
        if ($noncePrefix === false || strlen($noncePrefix) !== self::GCM_NONCE_PREFIX_LENGTH) {
            throw new RuntimeException("Corrupted AEAD encrypted backup: invalid nonce prefix length.");
        }

        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ITERATIONS, 32, true);

        $chunkIndex = 0;
        $hasTerminated = false;
        $totalPlainBytes = 0;
        $hashCtx = hash_init('sha256');

        while (!feof($sourceHandle)) {
            $framePrefix = fread($sourceHandle, 21);
            if ($framePrefix === false || strlen($framePrefix) === 0) {
                if ($hasTerminated) {
                    break;
                }
                throw new RuntimeException("Unexpected end of file while reading chunk frame.");
            }

            if (strlen($framePrefix) < 21) {
                throw new RuntimeException("Truncated chunk frame header in encrypted backup.");
            }

            $len = unpack('Nlen', substr($framePrefix, 0, 4))['len'];
            $isLast = ord($framePrefix[4]) === 1;
            $tag = substr($framePrefix, 5, 16);

            $ciphertextChunk = '';
            $bytesRemaining = $len;
            while ($bytesRemaining > 0 && !feof($sourceHandle)) {
                $readSize = min($bytesRemaining, self::CHUNK_SIZE);
                $buf = fread($sourceHandle, $readSize);
                if ($buf === false || strlen($buf) === 0) {
                    break;
                }
                $ciphertextChunk .= $buf;
                $bytesRemaining -= strlen($buf);
            }

            if (strlen($ciphertextChunk) !== $len) {
                throw new RuntimeException("Truncated ciphertext chunk: expected {$len} bytes, got " . strlen($ciphertextChunk));
            }

            $nonce = $noncePrefix . pack('N', $chunkIndex);
            $aad = self::AEAD_MAGIC_HEADER . pack('N', $chunkIndex) . chr($isLast ? 1 : 0);

            $plaintextChunk = openssl_decrypt(
                $ciphertextChunk,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $aad
            );

            if ($plaintextChunk === false) {
                throw new RuntimeException("AEAD authentication failed: Tag mismatch, corrupted ciphertext, wrong key, or tampered chunk #{$chunkIndex}.");
            }

            if (strlen($plaintextChunk) > 0) {
                fwrite($outputStream, $plaintextChunk);
                hash_update($hashCtx, $plaintextChunk);
                $totalPlainBytes += strlen($plaintextChunk);
            }

            $chunkIndex++;
            if ($isLast) {
                $hasTerminated = true;
                break;
            }
        }

        if (!$hasTerminated) {
            throw new RuntimeException("Encrypted backup stream ended prematurely without terminal chunk flag.");
        }

        return [
            'format'        => 'AEAD_GCM_V1',
            'authenticated' => true,
            'bytes'         => $totalPlainBytes,
            'sha256'        => hash_final($hashCtx),
        ];
    }

    /**
     * Decrypt legacy OpenSSL AES-256-CBC stream with warning log.
     */
    protected function decryptLegacyCbcStream($sourceHandle, $outputStream, string $passphrase, string $salt): array
    {
        Log::warning("[BACKUP-DECRYPT] Decrypting legacy AES-256-CBC backup without authenticated AEAD envelope. Authenticity NOT guaranteed.");

        if (strlen($salt) !== 8) {
            throw new RuntimeException("Corrupted legacy OpenSSL encrypted file: invalid salt length.");
        }

        $derived = openssl_pbkdf2($passphrase, $salt, 48, self::PBKDF2_ITERATIONS, 'sha256');
        $key = substr($derived, 0, 32);
        $iv = substr($derived, 32, 16);

        $ciphertext = '';
        while (!feof($sourceHandle)) {
            $buf = fread($sourceHandle, self::CHUNK_SIZE);
            if ($buf !== false && strlen($buf) > 0) {
                $ciphertext .= $buf;
            }
        }

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new RuntimeException("Legacy CBC decryption failed. Invalid encryption key or corrupted ciphertext: " . openssl_error_string());
        }

        fwrite($outputStream, $plaintext);

        return [
            'format'        => 'LEGACY_CBC',
            'authenticated' => false,
            'warning'       => 'LEGACY — AUTHENTICITY NOT GUARANTEED',
            'bytes'         => strlen($plaintext),
            'sha256'        => hash('sha256', $plaintext),
        ];
    }

    /**
     * Helper to encrypt a file using legacy AES-256-CBC for regression and migration testing.
     */
    public function encryptLegacyCbcFile(string $sourcePlainPath, string $targetEncPath, ?string $customKey = null): array
    {
        $passphrase = $customKey ?? $this->getKey();
        if (empty($passphrase)) {
            throw new RuntimeException("Encryption passphrase cannot be empty.");
        }

        $sourceContent = file_get_contents($sourcePlainPath);
        $salt = openssl_random_pseudo_bytes(8);
        $derived = openssl_pbkdf2($passphrase, $salt, 48, self::PBKDF2_ITERATIONS, 'sha256');
        $key = substr($derived, 0, 32);
        $iv = substr($derived, 32, 16);

        $ct = openssl_encrypt($sourceContent, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $finalData = self::LEGACY_MAGIC_HEADER . $salt . $ct;

        File::ensureDirectoryExists(dirname($targetEncPath));
        file_put_contents($targetEncPath, $finalData);

        return [
            'encrypted'  => true,
            'cipher'     => 'aes-256-cbc',
            'format'     => 'LEGACY_CBC',
            'path'       => $targetEncPath,
            'size_bytes' => strlen($finalData),
            'sha256'     => hash('sha256', $finalData),
        ];
    }

    /**
     * Decrypt an archive to a temporary file in system temp dir and return the path.
     */
    public function decryptToTemp(string $sourceEncPath, ?string $customKey = null): string
    {
        $ext = str_ends_with($sourceEncPath, '.sql.gz.enc') ? '.sql.gz' : '.tar.gz';
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup_decrypted_' . uniqid() . $ext;
        $this->decryptFile($sourceEncPath, $tempPath, $customKey);
        return $tempPath;
    }

    /**
     * Inspect an archive to detect its encryption envelope format and authenticity classification.
     */
    public function inspectArchive(string $path): array
    {
        if (!file_exists($path)) {
            return ['exists' => false, 'format' => 'NONE', 'authenticated' => false];
        }

        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return ['exists' => true, 'format' => 'UNREADABLE', 'authenticated' => false];
        }

        $header = fread($fh, 16);
        fclose($fh);

        if ($header === self::AEAD_MAGIC_HEADER) {
            return [
                'exists'         => true,
                'encrypted'      => true,
                'format'         => 'AEAD_GCM_V1',
                'cipher'         => 'aes-256-gcm',
                'authenticated'  => true,
                'classification' => 'AUTHENTICATED_AEAD',
            ];
        }

        if (substr($header, 0, 8) === self::LEGACY_MAGIC_HEADER) {
            return [
                'exists'         => true,
                'encrypted'      => true,
                'format'         => 'LEGACY_CBC',
                'cipher'         => 'aes-256-cbc',
                'authenticated'  => false,
                'classification' => 'LEGACY — AUTHENTICITY NOT GUARANTEED',
            ];
        }

        $isEnc = str_ends_with($path, '.enc');
        return [
            'exists'         => true,
            'encrypted'      => $isEnc,
            'format'         => $isEnc ? 'UNKNOWN_ENCRYPTED' : 'PLAIN_OR_GZIP',
            'cipher'         => null,
            'authenticated'  => false,
            'classification' => $isEnc ? 'CORRUPT_OR_UNSUPPORTED' : 'UNENCRYPTED',
        ];
    }

    /**
     * Compute an HMAC-SHA256 signature over manifest data to prevent tampering.
     */
    public function computeManifestHmac(array $manifestData, ?string $customKey = null): string
    {
        $key = $customKey ?? $this->getKey();
        if (empty($key)) {
            throw new RuntimeException("Cannot compute manifest HMAC: encryption key is not set.");
        }

        unset($manifestData['manifest_hmac']);
        ksort($manifestData);
        $canonicalJson = json_encode($manifestData, JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $canonicalJson, $key);
    }

    /**
     * Verify the HMAC-SHA256 signature of a manifest against the encryption key.
     */
    public function verifyManifestHmac(array $manifestData, ?string $customKey = null): bool
    {
        if (empty($manifestData['manifest_hmac'])) {
            return false;
        }

        $recordedHmac = (string) $manifestData['manifest_hmac'];
        try {
            $expectedHmac = $this->computeManifestHmac($manifestData, $customKey);
            return hash_equals($expectedHmac, $recordedHmac);
        } catch (Throwable $e) {
            return false;
        }
    }
}
