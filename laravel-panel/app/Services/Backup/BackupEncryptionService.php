<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BackupEncryptionService
{
    public const MAGIC_HEADER = 'Salted__';
    public const PBKDF2_ITERATIONS = 10000;
    public const CHUNK_SIZE = 65536; // 64 KB (multiple of 16 for AES block alignment)

    /**
     * Check if backup encryption is enabled in configuration.
     */
    public function isEncryptionEnabled(): bool
    {
        return (bool) config('backup.encryption.enabled', false);
    }

    /**
     * Get configured cipher (defaults to aes-256-cbc).
     */
    public function getCipher(): string
    {
        return (string) config('backup.encryption.cipher', 'aes-256-cbc');
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
     * Encrypt a plaintext file to an encrypted file using OpenSSL AES-256-CBC with PBKDF2.
     * Generates standard OpenSSL-compatible envelope (Salted__ + 8-byte salt + ciphertext).
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

        $cipher = $this->getCipher();

        try {
            // Generate 8-byte random salt
            $salt = openssl_random_pseudo_bytes(8);
            if ($salt === false || strlen($salt) !== 8) {
                throw new RuntimeException("Failed to generate secure cryptographic salt.");
            }

            // Derive 32-byte key + 16-byte IV using PBKDF2 with SHA-256
            $derived = openssl_pbkdf2($passphrase, $salt, 48, self::PBKDF2_ITERATIONS, 'sha256');
            $key = substr($derived, 0, 32);
            $iv = substr($derived, 32, 16);

            // Write OpenSSL magic header and salt
            fwrite($targetHandle, self::MAGIC_HEADER . $salt);

            $currentIv = $iv;

            while (!feof($sourceHandle)) {
                $chunk = fread($sourceHandle, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException("Read error during encryption of [{$sourcePlainPath}].");
                }

                if (strlen($chunk) === 0) {
                    break;
                }

                $isLast = feof($sourceHandle);

                if ($isLast) {
                    // Final block uses standard PKCS#7 padding
                    $ciphertextChunk = openssl_encrypt($chunk, $cipher, $key, OPENSSL_RAW_DATA, $currentIv);
                } else {
                    // Intermediate blocks must be multiples of 16 bytes without padding
                    $ciphertextChunk = openssl_encrypt($chunk, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $currentIv);
                    $currentIv = substr($ciphertextChunk, -16);
                }

                if ($ciphertextChunk === false) {
                    throw new RuntimeException("OpenSSL encryption error: " . openssl_error_string());
                }

                fwrite($targetHandle, $ciphertextChunk);
            }

            fclose($sourceHandle);
            fclose($targetHandle);

            // Verify temporary encrypted file exists and has content
            if (!file_exists($tempEncPath) || filesize($tempEncPath) <= 16) {
                throw new RuntimeException("Encrypted temporary file is invalid or empty: [{$tempEncPath}]");
            }

            // Atomic promotion
            if (!rename($tempEncPath, $targetEncPath)) {
                throw new RuntimeException("Failed to atomically promote encrypted file to [{$targetEncPath}]");
            }

            $encSize = filesize($targetEncPath);
            $encSha256 = hash_file('sha256', $targetEncPath);

            return [
                'encrypted'  => true,
                'cipher'     => $cipher,
                'path'       => $targetEncPath,
                'size_bytes' => $encSize,
                'sha256'     => $encSha256,
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
     * Decrypt an OpenSSL AES-256-CBC encrypted file back to plaintext.
     *
     * @param string $sourceEncPath
     * @param string $targetPlainPath
     * @param string|null $customKey
     * @return array
     * @throws Throwable
     */
    public function decryptFile(string $sourceEncPath, string $targetPlainPath, ?string $customKey = null): array
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

        $magic = fread($sourceHandle, 8);
        if ($magic !== self::MAGIC_HEADER) {
            fclose($sourceHandle);
            throw new RuntimeException("File does not contain valid OpenSSL encrypted header (expected 'Salted__').");
        }

        $salt = fread($sourceHandle, 8);
        if (strlen($salt) !== 8) {
            fclose($sourceHandle);
            throw new RuntimeException("Corrupted OpenSSL encrypted file: invalid salt length.");
        }

        $derived = openssl_pbkdf2($passphrase, $salt, 48, self::PBKDF2_ITERATIONS, 'sha256');
        $key = substr($derived, 0, 32);
        $iv = substr($derived, 32, 16);

        $tempPlainPath = "{$targetPlainPath}.tmp";
        File::ensureDirectoryExists(dirname($tempPlainPath));
        $targetHandle = @fopen($tempPlainPath, 'wb');
        if (!$targetHandle) {
            fclose($sourceHandle);
            throw new RuntimeException("Failed to open target plaintext stream for writing: [{$tempPlainPath}]");
        }

        $cipher = $this->getCipher();

        try {
            // Read all ciphertext
            $ciphertext = '';
            while (!feof($sourceHandle)) {
                $buf = fread($sourceHandle, self::CHUNK_SIZE);
                if ($buf !== false && strlen($buf) > 0) {
                    $ciphertext .= $buf;
                }
            }
            fclose($sourceHandle);

            $plaintext = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
            if ($plaintext === false) {
                throw new RuntimeException("Decryption failed. Invalid encryption key or corrupted ciphertext: " . openssl_error_string());
            }

            fwrite($targetHandle, $plaintext);
            fclose($targetHandle);

            if (!rename($tempPlainPath, $targetPlainPath)) {
                throw new RuntimeException("Failed to promote decrypted file to [{$targetPlainPath}]");
            }

            return [
                'path'       => $targetPlainPath,
                'size_bytes' => filesize($targetPlainPath),
                'sha256'     => hash_file('sha256', $targetPlainPath),
            ];

        } catch (Throwable $e) {
            if (is_resource($sourceHandle)) {
                fclose($sourceHandle);
            }
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
     * Decrypt an archive to a temporary file in system temp dir and return the path.
     */
    public function decryptToTemp(string $sourceEncPath, ?string $customKey = null): string
    {
        $ext = str_ends_with($sourceEncPath, '.sql.gz.enc') ? '.sql.gz' : '.tar.gz';
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup_decrypted_' . uniqid() . $ext;
        $this->decryptFile($sourceEncPath, $tempPath, $customKey);
        return $tempPath;
    }
}
