<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\Backup\BackupRetentionService;
use App\Services\Backup\BackupVerificationService;
use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\MailStorageBackupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupAndDisasterRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected string $testTempDir;
    protected string $testMailDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testTempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emailsaas_backup_test_' . uniqid();
        $this->testMailDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emailsaas_vmail_test_' . uniqid();

        File::ensureDirectoryExists($this->testTempDir . '/db');
        File::ensureDirectoryExists($this->testTempDir . '/vmail');
        File::ensureDirectoryExists($this->testMailDir);

        config(['backup.storage_path' => $this->testTempDir]);
        config(['backup.vmail_source_path' => $this->testMailDir]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testTempDir)) {
            File::deleteDirectory($this->testTempDir);
        }
        if (File::isDirectory($this->testMailDir)) {
            File::deleteDirectory($this->testMailDir);
        }

        parent::tearDown();
    }

    public function test_database_backup_creates_compressed_archive_and_sha256_manifest(): void
    {
        User::factory()->create(['email' => 'backup_test_user@example.com']);

        $service = new DatabaseBackupService($this->testTempDir . '/db');
        $manifest = $service->backup();

        $this->assertFileExists($manifest['path']);
        $this->assertStringEndsWith('.sql.gz', $manifest['filename']);
        $this->assertGreaterThan(0, $manifest['size_bytes']);

        $manifestFile = "{$manifest['path']}.manifest.json";
        $this->assertFileExists($manifestFile);

        $json = json_decode(file_get_contents($manifestFile), true);
        $this->assertIsArray($json);
        $this->assertSame('database', $json['type']);
        $this->assertSame($manifest['sha256'], $json['sha256']);
        $this->assertSame(hash_file('sha256', $manifest['path']), $manifest['sha256']);
    }

    public function test_mail_storage_backup_preserves_maildir_contents_and_generates_manifest(): void
    {
        // Setup synthetic Maildir structure
        $userMaildir = $this->testMailDir . '/example.com/user1';
        File::ensureDirectoryExists("{$userMaildir}/cur");
        File::ensureDirectoryExists("{$userMaildir}/new");
        File::ensureDirectoryExists("{$userMaildir}/tmp");

        $msg1 = "From: sender@example.com\nTo: user1@example.com\nSubject: Test Email 1\n\nBody 1";
        $msg2 = "From: sender@example.com\nTo: user1@example.com\nSubject: Test Email 2\n\nBody 2";

        file_put_contents("{$userMaildir}/cur/1000000001.M1P1.host:2,S", $msg1);
        file_put_contents("{$userMaildir}/new/1000000002.M2P2.host:2,", $msg2);

        $service = new MailStorageBackupService($this->testTempDir . '/vmail', $this->testMailDir);
        $manifest = $service->backup();

        $this->assertFileExists($manifest['path']);
        $this->assertStringEndsWith('.tar.gz', $manifest['filename']);
        $this->assertGreaterThan(0, $manifest['size_bytes']);
        $this->assertSame(2, $manifest['file_count']);

        $manifestFile = "{$manifest['path']}.manifest.json";
        $this->assertFileExists($manifestFile);

        $json = json_decode(file_get_contents($manifestFile), true);
        $this->assertSame('mail_storage', $json['type']);
        $this->assertSame($manifest['sha256'], $json['sha256']);
        $this->assertSame(hash_file('sha256', $manifest['path']), $manifest['sha256']);
    }

    public function test_backup_verification_service_detects_integrity_and_corruption(): void
    {
        User::factory()->create(['email' => 'verify_test_user@example.com']);
        $dbService = new DatabaseBackupService($this->testTempDir . '/db');
        $manifest = $dbService->backup();

        $verifyService = new BackupVerificationService();

        // 1. Valid Archive Verification
        $validResult = $verifyService->verifyArchiveIntegrity($manifest['path'], $manifest['sha256']);
        $this->assertTrue($validResult['valid']);
        $this->assertEmpty($validResult['errors']);
        $this->assertSame($manifest['sha256'], $validResult['sha256']);

        // 2. Checksum Mismatch Detection
        $wrongHash = str_repeat('a', 64);
        $mismatchResult = $verifyService->verifyArchiveIntegrity($manifest['path'], $wrongHash);
        $this->assertFalse($mismatchResult['valid']);
        $this->assertNotEmpty($mismatchResult['errors']);

        // 3. Corrupted Gzip Stream Detection
        $corruptFile = $this->testTempDir . '/db/corrupt.sql.gz';
        file_put_contents($corruptFile, 'THIS_IS_CORRUPTED_NON_GZIP_BINARY_DATA');
        $corruptResult = $verifyService->verifyArchiveIntegrity($corruptFile);
        $this->assertFalse($corruptResult['valid']);
        $this->assertNotEmpty($corruptResult['errors']);
    }

    public function test_isolated_database_restore_verification(): void
    {
        User::factory()->create(['email' => 'restore_check@example.com']);
        $dbService = new DatabaseBackupService($this->testTempDir . '/db');
        $manifest = $dbService->backup();

        $verifyService = new BackupVerificationService();
        $restoreResult = $verifyService->verifyIsolatedDatabaseRestore($manifest['path']);

        $this->assertTrue($restoreResult['restored']);
        $this->assertIsArray($restoreResult['tables']);
        $this->assertContains('users', $restoreResult['tables']);
        $this->assertGreaterThan(0, $restoreResult['table_counts']['users']);
    }

    public function test_isolated_mail_restore_verification(): void
    {
        // Create synthetic Maildir structure
        $userMaildir = $this->testMailDir . '/tenant.com/support';
        File::ensureDirectoryExists("{$userMaildir}/cur");
        File::ensureDirectoryExists("{$userMaildir}/new");
        file_put_contents("{$userMaildir}/cur/mail1.eml", "Hello Mail 1");

        $mailService = new MailStorageBackupService($this->testTempDir . '/vmail', $this->testMailDir);
        $manifest = $mailService->backup();

        $restoreTarget = $this->testTempDir . '/restore_vmail_test';
        $verifyService = new BackupVerificationService();
        $restoreResult = $verifyService->verifyMailStorageBackup($manifest['path'], $restoreTarget);

        $this->assertTrue($restoreResult['restored']);
        $this->assertTrue($restoreResult['maildir_structure_valid']);
        $this->assertFileExists($restoreTarget . '/tenant.com/support/cur/mail1.eml');
        $this->assertSame("Hello Mail 1", file_get_contents($restoreTarget . '/tenant.com/support/cur/mail1.eml'));
    }

    public function test_retention_service_never_prunes_newest_or_single_remaining_backup(): void
    {
        $dir = $this->testTempDir . '/db';
        $retentionService = new BackupRetentionService($this->testTempDir);

        // Case 1: Single Backup (Must Never Be Pruned)
        $singleFile = "{$dir}/db_backup_20260101_000000.sql.gz";
        file_put_contents($singleFile, 'backup content');
        touch($singleFile, time() - 86400 * 10);

        $res1 = $retentionService->pruneDirectory($dir, 1);
        $this->assertEmpty($res1['pruned']);
        $this->assertCount(1, $res1['retained']);
        $this->assertFileExists($singleFile);

        // Case 2: Multiple Backups (Oldest Pruned, Newest Always Retained)
        $newestFile = "{$dir}/db_backup_20260105_000000.sql.gz";
        $middleFile = "{$dir}/db_backup_20260103_000000.sql.gz";
        $oldestFile = "{$dir}/db_backup_20260101_000000.sql.gz";

        file_put_contents($newestFile, 'newest backup');
        touch($newestFile, time() - 3600); // 1 hour ago

        file_put_contents($middleFile, 'middle backup');
        touch($middleFile, time() - 86400 * 2); // 2 days ago

        file_put_contents($oldestFile, 'oldest backup');
        touch($oldestFile, time() - 86400 * 5); // 5 days ago

        // Stale .tmp file (older than 24 hours)
        $staleTmp = "{$dir}/db_backup_temp.sql.gz.tmp";
        file_put_contents($staleTmp, 'stale incomplete tmp');
        touch($staleTmp, time() - 86400 * 2);

        // Keep 2 backups
        $res2 = $retentionService->pruneDirectory($dir, 2);

        $this->assertContains(basename($oldestFile), $res2['pruned']);
        $this->assertContains(basename($newestFile), $res2['retained']);
        $this->assertContains(basename($middleFile), $res2['retained']);
        $this->assertFileDoesNotExist($oldestFile);
        $this->assertFileExists($newestFile);
        $this->assertFileDoesNotExist($staleTmp);
        $this->assertContains(basename($staleTmp), $res2['cleaned_tmp']);
    }

    public function test_artisan_backup_commands_execute_successfully(): void
    {
        // 1. backup:database
        $exitCode = Artisan::call('backup:database', ['--path' => $this->testTempDir . '/db']);
        $this->assertSame(0, $exitCode);

        // 2. backup:vmail
        $userMaildir = $this->testMailDir . '/testdomain.com/user';
        File::ensureDirectoryExists("{$userMaildir}/cur");
        file_put_contents("{$userMaildir}/cur/1.msg", "Msg");

        $exitCode = Artisan::call('backup:vmail', [
            '--source' => $this->testMailDir,
            '--path'   => $this->testTempDir . '/vmail',
        ]);
        $this->assertSame(0, $exitCode);

        // 3. backup:status
        $exitCode = Artisan::call('backup:status');
        $this->assertSame(0, $exitCode);

        // 4. backup:verify on generated database archive
        $dbFiles = File::glob($this->testTempDir . '/db/*.sql.gz');
        $this->assertNotEmpty($dbFiles);
        $exitCode = Artisan::call('backup:verify', [
            'path' => $dbFiles[0],
            '--dry-run-restore' => true,
        ]);
        $this->assertSame(0, $exitCode);

        // 5. backup:prune
        $exitCode = Artisan::call('backup:prune');
        $this->assertSame(0, $exitCode);

        // 6. backup:run with verify
        $exitCode = Artisan::call('backup:run', ['--verify' => true]);
        $this->assertSame(0, $exitCode);
    }

    public function test_concurrency_lock_prevents_overlapping_backup_runs(): void
    {
        $lock = Cache::lock('backup_run_master_lock', 120);
        $this->assertTrue($lock->get());

        // When locked, backup:run should gracefully skip execution
        $exitCode = Artisan::call('backup:run');
        $this->assertSame(0, $exitCode);

        $lock->release();
    }

    public function test_failure_safety_cleans_temporary_files_and_preserves_existing_backup(): void
    {
        $dbService = new DatabaseBackupService($this->testTempDir . '/db');

        // Create initial known-good backup
        $initial = $dbService->backup();
        $this->assertFileExists($initial['path']);
        $initialSize = filesize($initial['path']);

        // Attempt backup targeting a non-existent invalid database connection
        $threw = false;
        try {
            $dbService->backup(null, 'non_existent_invalid_connection');
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw);

        // Assert no lingering .tmp files exist
        $tmpFiles = File::glob($this->testTempDir . '/db/*.tmp');
        $this->assertEmpty($tmpFiles);

        // Assert initial known-good backup is untouched
        $this->assertFileExists($initial['path']);
        $this->assertSame($initialSize, filesize($initial['path']));
    }

    public function test_encrypted_database_backup_and_decryption_verification(): void
    {
        config([
            'backup.encryption.enabled' => true,
            'backup.encryption.key'     => 'test_secure_passphrase_987654321',
        ]);

        User::factory()->create(['email' => 'encrypted_user@example.com']);

        $service = new DatabaseBackupService($this->testTempDir . '/db');
        $manifest = $service->backup();

        $this->assertFileExists($manifest['path']);
        $this->assertStringEndsWith('.sql.gz.enc', $manifest['filename']);
        $this->assertTrue($manifest['encrypted']);
        $this->assertSame('aes-256-cbc', $manifest['cipher']);
        $this->assertNotNull($manifest['unencrypted_sha256']);

        // Check OpenSSL header
        $fh = fopen($manifest['path'], 'rb');
        $magic = fread($fh, 8);
        fclose($fh);
        $this->assertSame('Salted__', $magic);

        // Verification with valid key
        $verificationService = new BackupVerificationService();
        $verifyResult = $verificationService->verifyDatabaseBackup($manifest['path']);
        $this->assertTrue($verifyResult['valid']);

        // Isolated restore test with decryption
        $restoreResult = $verificationService->verifyIsolatedDatabaseRestore($manifest['path']);
        $this->assertTrue($restoreResult['restored']);
        $this->assertContains('users', $restoreResult['tables']);
        $this->assertGreaterThan(0, $restoreResult['table_counts']['users']);

        // Verification with wrong key fails
        $wrongKeyResult = $verificationService->verifyArchiveIntegrity($manifest['path'], null, 'completely_wrong_key');
        $this->assertFalse($wrongKeyResult['valid']);
        $this->assertNotEmpty($wrongKeyResult['errors']);
    }

    public function test_encrypted_mail_storage_backup_and_decryption_verification(): void
    {
        config([
            'backup.encryption.enabled' => true,
            'backup.encryption.key'     => 'mail_secret_key_1122334455',
        ]);

        $userMaildir = $this->testMailDir . '/secure-domain.com/ceo';
        File::ensureDirectoryExists("{$userMaildir}/cur");
        file_put_contents("{$userMaildir}/cur/secret.eml", "Top secret email body");

        $service = new MailStorageBackupService($this->testTempDir . '/vmail', $this->testMailDir);
        $manifest = $service->backup();

        $this->assertFileExists($manifest['path']);
        $this->assertStringEndsWith('.tar.gz.enc', $manifest['filename']);
        $this->assertTrue($manifest['encrypted']);

        // Verification with valid key
        $verificationService = new BackupVerificationService();
        $verifyResult = $verificationService->verifyArchiveIntegrity($manifest['path']);
        $this->assertTrue($verifyResult['valid']);

        // Isolated restore test
        $restoreTarget = $this->testTempDir . '/restore_enc_vmail';
        $restoreResult = $verificationService->verifyMailStorageBackup($manifest['path'], $restoreTarget);
        $this->assertTrue($restoreResult['restored']);
        $this->assertFileExists($restoreTarget . '/secure-domain.com/ceo/cur/secret.eml');
        $this->assertSame("Top secret email body", file_get_contents($restoreTarget . '/secure-domain.com/ceo/cur/secret.eml'));
    }

    public function test_calendar_aware_retention_with_gfs_daily_weekly_monthly(): void
    {
        $dir = $this->testTempDir . '/db';
        $retentionService = new BackupRetentionService($this->testTempDir);

        $now = Carbon::now('UTC');

        // Create archives across different calendar points:
        $fileDay0 = "{$dir}/db_backup_" . $now->copy()->format('Ymd_His') . ".sql.gz";
        $fileDay2 = "{$dir}/db_backup_" . $now->copy()->subDays(2)->format('Ymd_His') . ".sql.gz";
        $fileDay4 = "{$dir}/db_backup_" . $now->copy()->subDays(4)->format('Ymd_His') . ".sql.gz";
        $fileDay6 = "{$dir}/db_backup_" . $now->copy()->subDays(6)->format('Ymd_His') . ".sql.gz";
        $fileWeek2 = "{$dir}/db_backup_" . $now->copy()->subWeeks(2)->format('Ymd_His') . ".sql.gz";
        $fileMonth1 = "{$dir}/db_backup_" . $now->copy()->subMonths(1)->format('Ymd_His') . ".sql.gz";
        $fileMonth2 = "{$dir}/db_backup_" . $now->copy()->subMonths(2)->format('Ymd_His') . ".sql.gz";
        $fileMonth5 = "{$dir}/db_backup_" . $now->copy()->subMonths(5)->format('Ymd_His') . ".sql.gz";

        $allFiles = [$fileDay0, $fileDay2, $fileDay4, $fileDay6, $fileWeek2, $fileMonth1, $fileMonth2, $fileMonth5];
        foreach ($allFiles as $f) {
            file_put_contents($f, 'synthetic backup content');
            file_put_contents("{$f}.manifest.json", json_encode(['sha256' => hash_file('sha256', $f)]));
        }

        $res = $retentionService->pruneDirectory($dir);

        // Assert recent daily, weekly, monthly are retained
        $this->assertContains(basename($fileDay0), $res['retained']);
        $this->assertContains(basename($fileDay2), $res['retained']);
        $this->assertContains(basename($fileWeek2), $res['retained']);
        $this->assertContains(basename($fileMonth1), $res['retained']);
        $this->assertContains(basename($fileMonth2), $res['retained']);

        // Assert ancient 5-month-old backup was pruned
        $this->assertContains(basename($fileMonth5), $res['pruned']);
        $this->assertFileDoesNotExist($fileMonth5);
        $this->assertFileDoesNotExist("{$fileMonth5}.manifest.json");
    }

    public function test_offsite_service_sync_and_fail_safe_semantics(): void
    {
        $remoteDir = $this->testTempDir . '/remote_offsite';
        File::ensureDirectoryExists($remoteDir);

        config([
            'backup.offsite.enabled'   => true,
            'backup.offsite.transport' => 'local',
            'backup.offsite.path'      => $remoteDir,
        ]);

        $dbService = new DatabaseBackupService($this->testTempDir . '/db');
        $manifest = $dbService->backup();

        $offsiteService = new \App\Services\Backup\BackupOffsiteService();
        $result = $offsiteService->syncBackup($manifest['path']);

        $this->assertTrue($result['success']);
        $this->assertSame('COPIED', $result['status']);
        $this->assertFileExists($remoteDir . '/' . basename($manifest['path']));
        $this->assertFileExists($remoteDir . '/' . basename($manifest['path']) . '.manifest.json');

        // Test fail-safe behavior: failure must NOT delete or mutate local backup
        config([
            'backup.offsite.transport' => 'custom',
            'backup.offsite.command'   => 'exit 1',
        ]);

        $failResult = $offsiteService->syncBackup($manifest['path']);
        $this->assertFalse($failResult['success']);
        $this->assertSame('FAILED', $failResult['status']);

        // Invariant: local backup remains intact and untouched
        $this->assertFileExists($manifest['path']);
        $this->assertFileExists($manifest['path'] . '.manifest.json');
    }

    public function test_encryption_fails_closed_when_key_is_missing(): void
    {
        config([
            'backup.encryption.enabled'  => true,
            'backup.encryption.key'      => null,
            'backup.encryption.key_path' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('neither BACKUP_ENCRYPTION_KEY nor BACKUP_ENCRYPTION_KEY_PATH');

        $dbService = new DatabaseBackupService($this->testTempDir . '/db');
        $dbService->backup();
    }
}
