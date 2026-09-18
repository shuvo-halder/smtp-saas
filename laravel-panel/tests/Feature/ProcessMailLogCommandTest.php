<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ProcessMailLogCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $tenant;
    private Mailbox $mailbox;
    private string $tempLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create([
            'name' => 'Mail Plan',
            'slug' => 'mail-plan',
            'max_domains' => 2,
            'max_mailboxes_per_domain' => 5,
            'storage_mb_per_mailbox' => 500,
            'price_monthly' => 10,
            'price_yearly' => 100,
            'is_active' => true,
        ]);

        $this->tenant = User::create([
            'name' => 'Test Tenant',
            'email' => 'admin@testcorp.com',
            'password' => bcrypt('password'),
            'status' => 'active',
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        $domain = Domain::create([
            'user_id' => $this->tenant->id,
            'domain_name' => 'testcorp.com',
            'status' => 'active',
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id' => $domain->id,
            'local_part' => 'support',
            'email' => 'support@testcorp.com',
            'password' => 'secret_hash',
            'is_active' => true,
        ]);

        $this->tempLogPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mail_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLogPath)) {
            @unlink($this->tempLogPath);
        }
        parent::tearDown();
    }

    public function test_command_handles_missing_file_safely(): void
    {
        $exitCode = Artisan::call('mail:process-log', [
            '--path' => '/nonexistent/path/mail.log',
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('does not exist', Artisan::output());
    }

    public function test_command_handles_disabled_state(): void
    {
        Config::set('mail_abuse.enabled', false);

        $exitCode = Artisan::call('mail:process-log');

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('disabled', Artisan::output());
    }

    public function test_command_skips_when_lock_already_held(): void
    {
        file_put_contents($this->tempLogPath, "sample log line\n");

        $lock = Cache::lock('mail_process_log_lock', 60);
        $lock->get();

        $exitCode = Artisan::call('mail:process-log', [
            '--path' => $this->tempLogPath,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Another mail log processing command is currently running', Artisan::output());

        $lock->release();
    }

    public function test_normal_command_execution_processes_log_fixture(): void
    {
        $logContent = implode("\n", [
            'Sep 19 01:23:45 mail postfix/qmgr[10416]: 4Y1z9M2dZ1z3x4y: from=<support@testcorp.com>, size=2048, nrcpt=1 (queue active)',
            'Sep 19 01:23:46 mail postfix/smtp[10430]: 4Y1z9M2dZ1z3x4y: to=<invalid@remote.com>, relay=mx.remote.com[93.184.216.34]:25, delay=0.8, delays=0.01/0/0.4/0.39, dsn=5.1.1, status=bounced (host mx.remote.com said: 550 5.1.1 User unknown)',
            'Sep 19 01:23:47 mail postfix/smtp[10430]: 4Y1z9M2dZ1z9999: to=<good@remote.com>, relay=mx.remote.com[93.184.216.34]:25, delay=1.2, delays=0.01/0/0.4/0.79, dsn=2.0.0, status=sent (250 2.0.0 OK)',
            'Sep 19 01:23:48 mail postfix/cleanup[10415]: 4Y1z9M2dZ1z3x4y: message-id=<msg123@testcorp.com>',
        ]) . "\n";

        file_put_contents($this->tempLogPath, $logContent);

        // Redis mocks for parser cursor and abuse detection
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('setex')->andReturn(true);
        Redis::shouldReceive('incr')->andReturn(1);
        Redis::shouldReceive('expire')->andReturn(true);
        Redis::shouldReceive('del')->andReturn(1);

        $exitCode = Artisan::call('mail:process-log', [
            '--path' => $this->tempLogPath,
            '--lines' => 100,
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Mail log processing completed', $output);
        $this->assertStringContainsString('Read: 4', $output);
    }

    public function test_dry_run_mode_does_not_save_cursor(): void
    {
        $logContent = "Sep 19 01:23:45 mail postfix/qmgr[10416]: 4Y1z9M2dZ1z3x4y: from=<support@testcorp.com>, size=2048, nrcpt=1\n";
        file_put_contents($this->tempLogPath, $logContent);

        // Cursor should not be saved in dry run
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('set')->never();
        Redis::shouldReceive('setex')->never();

        $exitCode = Artisan::call('mail:process-log', [
            '--path' => $this->tempLogPath,
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('[DRY-RUN]', $output);
    }
}
