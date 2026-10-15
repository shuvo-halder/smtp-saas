<?php

namespace Tests\Feature;

use App\Services\Backup\BackupEncryptionService;
use App\Services\Backup\BackupVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class AuthenticatedBackupEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected string $testTempDir;
    protected BackupEncryptionService $encryptionService;
    protected BackupVerificationService $verificationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testTempDir = storage_path('framework/testing/auth_backup_' . uniqid());
        File::ensureDirectoryExists($this->testTempDir);

        $this->encryptionService = new BackupEncryptionService();
        $this->verificationService = new BackupVerificationService($this->encryptionService);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testTempDir)) {
            File::deleteDirectory($this->testTempDir);
        }

        parent::tearDown();
    }

    /**
     * Test 1: Authenticated encryption creates valid envelope with EMAILSAAS_GCM_V1 header.
     */
    public function test_authenticated_encryption_creates_valid_envelope_with_magic_header(): void
    {
        $plainFile = $this->testTempDir . '/sample.txt';
        $encFile = $this->testTempDir . '/sample.txt.enc';
        file_put_contents($plainFile, 'Test payload for AEAD envelope verification.');

        $key = 'SuperSecretCryptographicPassphrase123!';
        $result = $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        $this->assertTrue($result['encrypted']);
        $this->assertSame('aes-256-gcm', $result['cipher']);
        $this->assertSame('AEAD_GCM_V1', $result['format']);
        $this->assertFileExists($encFile);

        // Inspect 40-byte header
        $fh = fopen($encFile, 'rb');
        $magic = fread($fh, 16);
        $salt = fread($fh, 16);
        $noncePrefix = fread($fh, 8);
        fclose($fh);

        $this->assertSame('EMAILSAAS_GCM_V1', $magic);
        $this->assertSame(16, strlen($salt));
        $this->assertSame(8, strlen($noncePrefix));
    }

    /**
     * Test 2: Authenticated roundtrip encryption and decryption preserves content across multiple chunks.
     */
    public function test_authenticated_roundtrip_encryption_and_decryption_preserves_content(): void
    {
        $plainFile = $this->testTempDir . '/large_plain.bin';
        $encFile = $this->testTempDir . '/large_plain.bin.enc';
        $decFile = $this->testTempDir . '/large_plain.dec';

        // 180 KB content (spans across multiple 64 KB chunks: chunk 0, chunk 1, chunk 2)
        $randomPayload = random_bytes(180 * 1024);
        file_put_contents($plainFile, $randomPayload);

        $key = 'MultiChunkTestKey999$$';
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        $decResult = $this->encryptionService->decryptFile($encFile, $decFile, $key);

        $this->assertSame('AEAD_GCM_V1', $decResult['format']);
        $this->assertTrue($decResult['authenticated']);
        $this->assertFileExists($decFile);
        $this->assertSame(hash_file('sha256', $plainFile), hash_file('sha256', $decFile));
        $this->assertSame($randomPayload, file_get_contents($decFile));
    }

    /**
     * Test 3: Constant memory streaming behavior (O(1) buffer bounds).
     */
    public function test_constant_memory_streaming_behavior(): void
    {
        $plainFile = $this->testTempDir . '/streaming_sample.bin';
        $encFile = $this->testTempDir . '/streaming_sample.bin.enc';
        $decFile = $this->testTempDir . '/streaming_sample.dec';

        // Write 256 KB file
        $chunk = str_repeat('A', 65536);
        $fh = fopen($plainFile, 'wb');
        for ($i = 0; $i < 4; $i++) {
            fwrite($fh, $chunk);
        }
        fclose($fh);

        $key = 'StreamMemoryVerificationPassphrase1!';

        $memBefore = memory_get_usage();
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);
        $memAfterEnc = memory_get_usage();

        // Memory difference during streaming should be well below 5 MB
        $this->assertLessThan(5 * 1024 * 1024, abs($memAfterEnc - $memBefore));

        $this->encryptionService->decryptFile($encFile, $decFile, $key);
        $memAfterDec = memory_get_usage();
        $this->assertLessThan(5 * 1024 * 1024, abs($memAfterDec - $memBefore));

        $this->assertSame(filesize($plainFile), filesize($decFile));
    }

    /**
     * Test 4: Empty (0-byte) file encryption and decryption.
     */
    public function test_empty_file_encryption_and_decryption(): void
    {
        $plainFile = $this->testTempDir . '/empty.bin';
        $encFile = $this->testTempDir . '/empty.bin.enc';
        $decFile = $this->testTempDir . '/empty.dec';
        file_put_contents($plainFile, '');

        $key = 'EmptyFileKey_12345';
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        $this->assertFileExists($encFile);
        $this->assertGreaterThanOrEqual(40 + 21, filesize($encFile)); // 40B header + 21B frame

        $result = $this->encryptionService->decryptFile($encFile, $decFile, $key);
        $this->assertTrue($result['authenticated']);
        $this->assertSame(0, filesize($decFile));
        $this->assertSame('', file_get_contents($decFile));
    }

    /**
     * Test 5: Decryption with invalid passphrase fails closed and removes partial output.
     */
    public function test_decryption_with_invalid_passphrase_fails_closed(): void
    {
        $plainFile = $this->testTempDir . '/secret.txt';
        $encFile = $this->testTempDir . '/secret.txt.enc';
        $decFile = $this->testTempDir . '/secret.dec';
        file_put_contents($plainFile, 'Top secret confidential information.');

        $correctKey = 'CorrectStrongKey123!';
        $wrongKey = 'WrongKeyAttempt456!';

        $this->encryptionService->encryptFile($plainFile, $encFile, $correctKey);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AEAD authentication failed|Tag mismatch/i');

        try {
            $this->encryptionService->decryptFile($encFile, $decFile, $wrongKey);
        } finally {
            // Fail-closed verification: output file must NOT exist
            $this->assertFileDoesNotExist($decFile);
            $this->assertFileDoesNotExist($decFile . '.tmp');
        }
    }

    /**
     * Test 6: Ciphertext single-bit flip triggers authentication failure.
     */
    public function test_ciphertext_single_bit_flip_triggers_authentication_failure(): void
    {
        $plainFile = $this->testTempDir . '/tamper_ct.txt';
        $encFile = $this->testTempDir . '/tamper_ct.txt.enc';
        $decFile = $this->testTempDir . '/tamper_ct.dec';
        file_put_contents($plainFile, 'Data to be bit-flipped in ciphertext.');

        $key = 'BitFlipKey_777';
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        // Read file contents and flip 1 bit in ciphertext (past 40B header + 21B frame header)
        $encData = file_get_contents($encFile);
        $flipPos = 40 + 21 + 5; // In chunk ciphertext
        $encData[$flipPos] = chr(ord($encData[$flipPos]) ^ 0x01);
        file_put_contents($encFile, $encData);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AEAD authentication failed|Tag mismatch/i');

        try {
            $this->encryptionService->decryptFile($encFile, $decFile, $key);
        } finally {
            $this->assertFileDoesNotExist($decFile);
        }
    }

    /**
     * Test 7: Authentication tag tampering triggers authentication failure.
     */
    public function test_authentication_tag_tampering_triggers_authentication_failure(): void
    {
        $plainFile = $this->testTempDir . '/tamper_tag.txt';
        $encFile = $this->testTempDir . '/tamper_tag.txt.enc';
        $decFile = $this->testTempDir . '/tamper_tag.dec';
        file_put_contents($plainFile, 'Sensitive data with tampered auth tag.');

        $key = 'TagTamperKey_888';
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        // Flip 1 bit in the 16-byte tag (located at offset 40 + 4 + 1 = 45 to 60)
        $encData = file_get_contents($encFile);
        $tagPos = 40 + 5 + 3; // within tag
        $encData[$tagPos] = chr(ord($encData[$tagPos]) ^ 0x02);
        file_put_contents($encFile, $encData);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AEAD authentication failed|Tag mismatch/i');

        try {
            $this->encryptionService->decryptFile($encFile, $decFile, $key);
        } finally {
            $this->assertFileDoesNotExist($decFile);
        }
    }

    /**
     * Test 8: Associated Authenticated Data (AAD) tampering is detected.
     */
    public function test_associated_authenticated_data_aad_tampering_detected(): void
    {
        $plainFile = $this->testTempDir . '/tamper_aad.txt';
        $encFile = $this->testTempDir . '/tamper_aad.txt.enc';
        $decFile = $this->testTempDir . '/tamper_aad.dec';
        file_put_contents($plainFile, 'Data with tampered terminal flag in chunk frame.');

        $key = 'AadTamperKey_555';
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        // Frame structure: [len 4B][isLast 1B][tag 16B]
        // Offset 44 is the isLast flag (which is bound in AAD)
        $encData = file_get_contents($encFile);
        $encData[44] = chr(0); // Clear isLast flag to 0
        file_put_contents($encFile, $encData);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AEAD authentication failed|prematurely without terminal/i');

        try {
            $this->encryptionService->decryptFile($encFile, $decFile, $key);
        } finally {
            $this->assertFileDoesNotExist($decFile);
        }
    }

    /**
     * Test 9: Chunk reordering attack fails authentication.
     */
    public function test_chunk_reordering_attack_fails_authentication(): void
    {
        $plainFile = $this->testTempDir . '/multi_chunk_reorder.bin';
        $encFile = $this->testTempDir . '/multi_chunk_reorder.bin.enc';
        $decFile = $this->testTempDir . '/multi_chunk_reorder.dec';

        // 130 KB forces 2 full chunks (64 KB + 64 KB + 2 KB = 3 chunks)
        $data = random_bytes(130 * 1024);
        file_put_contents($plainFile, $data);

        $key = 'ReorderAttackKey_111';
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        // Read envelope header (40 bytes)
        $encContent = file_get_contents($encFile);
        $header = substr($encContent, 0, 40);

        // Chunk 0: 64KB ct -> frame = 4 + 1 + 16 + 65536 = 65557 bytes
        $chunk0 = substr($encContent, 40, 65557);
        // Chunk 1: 64KB ct -> frame = 65557 bytes
        $chunk1 = substr($encContent, 40 + 65557, 65557);
        $chunkRemainder = substr($encContent, 40 + (65557 * 2));

        // Swap chunk 0 and chunk 1
        $swapped = $header . $chunk1 . $chunk0 . $chunkRemainder;
        file_put_contents($encFile, $swapped);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AEAD authentication failed/i');

        try {
            $this->encryptionService->decryptFile($encFile, $decFile, $key);
        } finally {
            $this->assertFileDoesNotExist($decFile);
        }
    }

    /**
     * Test 10: Chunk truncation or dropped terminal chunk fails authentication.
     */
    public function test_chunk_truncation_or_dropped_terminal_chunk_fails(): void
    {
        $plainFile = $this->testTempDir . '/truncated.bin';
        $encFile = $this->testTempDir . '/truncated.bin.enc';
        $decFile = $this->testTempDir . '/truncated.dec';
        file_put_contents($plainFile, str_repeat('X', 80000));

        $key = 'TruncateTestKey_222';
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        // Truncate file by dropping the last 500 bytes
        $encContent = file_get_contents($encFile);
        $truncated = substr($encContent, 0, strlen($encContent) - 500);
        file_put_contents($encFile, $truncated);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Truncated|Unexpected end of file|prematurely without terminal/i');

        try {
            $this->encryptionService->decryptFile($encFile, $decFile, $key);
        } finally {
            $this->assertFileDoesNotExist($decFile);
        }
    }

    /**
     * Test 11: Corrupted header fails immediately.
     */
    public function test_corrupted_header_fails_immediately(): void
    {
        $plainFile = $this->testTempDir . '/bad_header.txt';
        $encFile = $this->testTempDir . '/bad_header.txt.enc';
        $decFile = $this->testTempDir . '/bad_header.dec';
        file_put_contents($plainFile, 'Header corruption test.');

        $key = 'HeaderTestKey_333';
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        // Corrupt magic header
        $encContent = file_get_contents($encFile);
        $corrupt = 'INVALID_MAGIC___' . substr($encContent, 16);
        file_put_contents($encFile, $corrupt);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/valid encrypted header/i');

        try {
            $this->encryptionService->decryptFile($encFile, $decFile, $key);
        } finally {
            $this->assertFileDoesNotExist($decFile);
        }
    }

    /**
     * Test 12: Backward compatible legacy CBC decryption with isolation.
     */
    public function test_backward_compatible_legacy_cbc_decryption_with_isolation(): void
    {
        $plainFile = $this->testTempDir . '/legacy_plain.txt';
        $legacyEncFile = $this->testTempDir . '/legacy.enc';
        $decFile = $this->testTempDir . '/legacy.dec';
        $content = 'Legacy MariaDB dump plaintext from Step 17.';
        file_put_contents($plainFile, $content);

        $key = 'LegacyCbcMigrationKey_444';
        $this->encryptionService->encryptLegacyCbcFile($plainFile, $legacyEncFile, $key);

        $this->assertFileExists($legacyEncFile);

        // Verify decryption succeeds and logs warning
        $decResult = $this->encryptionService->decryptFile($legacyEncFile, $decFile, $key);

        $this->assertSame('LEGACY_CBC', $decResult['format']);
        $this->assertFalse($decResult['authenticated']);
        $this->assertSame('LEGACY — AUTHENTICITY NOT GUARANTEED', $decResult['warning']);
        $this->assertSame($content, file_get_contents($decFile));
    }

    /**
     * Test 13: Legacy CBC rejected when AEAD strictly required / accurately classified.
     */
    public function test_legacy_cbc_rejected_when_aead_strictly_required(): void
    {
        $plainFile = $this->testTempDir . '/inspect_sample.txt';
        $aeadFile = $this->testTempDir . '/sample_aead.enc';
        $legacyFile = $this->testTempDir . '/sample_legacy.enc';
        file_put_contents($plainFile, 'Archive inspection classification sample.');

        $key = 'InspectKey_555';
        $this->encryptionService->encryptFile($plainFile, $aeadFile, $key);
        $this->encryptionService->encryptLegacyCbcFile($plainFile, $legacyFile, $key);

        $aeadInspect = $this->encryptionService->inspectArchive($aeadFile);
        $this->assertSame('AEAD_GCM_V1', $aeadInspect['format']);
        $this->assertTrue($aeadInspect['authenticated']);
        $this->assertSame('AUTHENTICATED_AEAD', $aeadInspect['classification']);

        $legacyInspect = $this->encryptionService->inspectArchive($legacyFile);
        $this->assertSame('LEGACY_CBC', $legacyInspect['format']);
        $this->assertFalse($legacyInspect['authenticated']);
        $this->assertSame('LEGACY — AUTHENTICITY NOT GUARANTEED', $legacyInspect['classification']);
    }

    /**
     * Test 14: Companion manifest HMAC-SHA256 generation, verification, and tampering detection.
     */
    public function test_companion_manifest_hmac_sha256_generation_and_verification(): void
    {
        $key = 'ManifestHmacSecretKey_666';

        $manifest = [
            'backup_id'          => 'db_20261016_010000',
            'type'               => 'database',
            'filename'           => 'backup.sql.gz.enc',
            'size_bytes'         => 123456,
            'sha256'             => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'encrypted'          => true,
            'cipher'             => 'aes-256-gcm',
            'encryption_format'  => 'AEAD_GCM_V1',
        ];

        $manifest['manifest_hmac'] = $this->encryptionService->computeManifestHmac($manifest, $key);
        $this->assertNotEmpty($manifest['manifest_hmac']);

        // Verify valid HMAC
        $this->assertTrue($this->encryptionService->verifyManifestHmac($manifest, $key));

        // Tamper with size_bytes
        $tamperedManifest = $manifest;
        $tamperedManifest['size_bytes'] = 999999;
        $this->assertFalse($this->encryptionService->verifyManifestHmac($tamperedManifest, $key));

        // Tamper with sha256
        $tamperedSha = $manifest;
        $tamperedSha['sha256'] = '0000000000000000000000000000000000000000000000000000000000000000';
        $this->assertFalse($this->encryptionService->verifyManifestHmac($tamperedSha, $key));

        // Verify with wrong key fails
        $this->assertFalse($this->encryptionService->verifyManifestHmac($manifest, 'WrongHmacKey!'));
    }

    /**
     * Test 15: CLI backup:decrypt command works in both file mode and --stdout streaming mode.
     */
    public function test_cli_backup_decrypt_command_file_and_stdout_modes(): void
    {
        $plainFile = $this->testTempDir . '/cli_sample.txt';
        $encFile = $this->testTempDir . '/cli_sample.txt.enc';
        $targetFile = $this->testTempDir . '/cli_decrypted.txt';
        $content = 'Testing Artisan backup:decrypt command in both file and stdout modes.';
        file_put_contents($plainFile, $content);

        $key = 'CliDecryptPassphrase_888!';
        $this->encryptionService->encryptFile($plainFile, $encFile, $key);

        // 1. File mode test via artisan
        $this->artisan('backup:decrypt', [
            'archive' => $encFile,
            'target'  => $targetFile,
            '--key'   => $key,
        ])->assertExitCode(0);

        $this->assertFileExists($targetFile);
        $this->assertSame($content, file_get_contents($targetFile));

        // 2. Stdout mode test
        $stdoutOutput = '';
        ob_start();
        $this->artisan('backup:decrypt', [
            'archive'  => $encFile,
            '--key'    => $key,
            '--stdout' => true,
        ])->assertExitCode(0);
        $stdoutOutput = ob_get_clean();

        $this->assertSame($content, $stdoutOutput);
    }

    /**
     * Test 16: Secret scrubbing ensures keys are never leaked in logs or error messages.
     */
    public function test_secret_scrubbing_no_keys_in_logs_or_errors(): void
    {
        $plainFile = $this->testTempDir . '/scrub.txt';
        $encFile = $this->testTempDir . '/scrub.txt.enc';
        file_put_contents($plainFile, 'Scrubbing test payload.');

        $sensitiveKey = 'ULTRA_SECRET_KEY_NEVER_PRINT_ME_12345';
        $this->encryptionService->encryptFile($plainFile, $encFile, $sensitiveKey);

        $wrongSensitiveKey = 'WRONG_ULTRA_SECRET_KEY_99999';

        try {
            $this->encryptionService->decryptFile($encFile, $this->testTempDir . '/fail.dec', $wrongSensitiveKey);
            $this->fail('Decryption should have thrown an exception.');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString($sensitiveKey, $message);
            $this->assertStringNotContainsString($wrongSensitiveKey, $message);
        }
    }
}
