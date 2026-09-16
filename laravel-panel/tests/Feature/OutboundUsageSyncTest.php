<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use App\Services\OutboundUsageSyncService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class OutboundUsageSyncTest extends TestCase
{
    use RefreshDatabase;

    private OutboundUsageSyncService $service;
    private User $tenant;
    private string $todayUtc;
    private string $yesterdayUtc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OutboundUsageSyncService();

        $plan = Plan::create([
            'name' => 'Standard Plan',
            'slug' => 'standard-plan',
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox' => 1024,
            'max_aliases_per_domain' => 5,
            'price_monthly' => 20,
            'price_yearly' => 200,
            'daily_outbound_recipients' => 500,
            'mailbox_daily_outbound_recipients' => 100,
        ]);

        $this->tenant = User::factory()->create([
            'plan_id' => $plan->id,
        ]);

        $this->todayUtc = Carbon::now('UTC')->format('Y-m-d');
        $this->yesterdayUtc = Carbon::now('UTC')->subDay()->format('Y-m-d');
    }

    public function test_basic_sync_inserts_usage_into_mariadb()
    {
        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('scan')
            ->once()
            ->andReturn([0, [$key]]);

        Redis::shouldReceive('get')
            ->once()
            ->with($key)
            ->andReturn('42');

        $result = $this->service->sync();

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['scanned_keys']);
        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['rows_inserted']);
        $this->assertEquals(0, $result['rows_updated']);

        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 42,
        ]);
    }

    public function test_sync_is_idempotent_and_does_not_double_count()
    {
        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}";

        // First run
        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
        Redis::shouldReceive('get')->once()->with($key)->andReturn('42');

        $result1 = $this->service->sync();
        $this->assertTrue($result1['success']);
        $this->assertEquals(1, $result1['rows_inserted']);

        // Second run with same Redis counter (42)
        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
        Redis::shouldReceive('get')->once()->with($key)->andReturn('42');

        $result2 = $this->service->sync();
        $this->assertTrue($result2['success']);
        $this->assertEquals(0, $result2['rows_inserted']);
        $this->assertEquals(0, $result2['rows_updated']);
        $this->assertEquals(1, $result2['rows_noop']);

        // Must still be 42, NEVER 84
        $this->assertDatabaseCount('tenant_outbound_usage', 1);
        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 42,
        ]);
    }

    public function test_sync_updates_when_redis_counter_increases()
    {
        TenantOutboundUsage::create([
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 40,
        ]);

        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
        Redis::shouldReceive('get')->once()->with($key)->andReturn('42');

        $result = $this->service->sync();

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['rows_updated']);
        $this->assertEquals(0, $result['rows_inserted']);

        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 42,
        ]);
    }

    public function test_sync_preserves_higher_mariadb_count_if_redis_decreases()
    {
        // MariaDB holds confirmed usage of 50
        TenantOutboundUsage::create([
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 50,
        ]);

        // Redis counter reset/flushed and restarted at 42
        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
        Redis::shouldReceive('get')->once()->with($key)->andReturn('42');

        $result = $this->service->sync();

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['rows_preserved']);
        $this->assertEquals(0, $result['rows_updated']);

        // Historical ledger must retain 50
        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 50,
        ]);
    }

    public function test_unlimited_plan_records_usage()
    {
        $this->tenant->plan->update([
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
        ]);

        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
        Redis::shouldReceive('get')->once()->with($key)->andReturn('1250');

        $result = $this->service->sync();

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 1250,
        ]);
    }

    public function test_zero_counter_in_redis_persisted()
    {
        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
        Redis::shouldReceive('get')->once()->with($key)->andReturn('0');

        $result = $this->service->sync();

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 0,
        ]);
    }

    public function test_missing_redis_key_does_not_fabricate_zero()
    {
        // No keys in Redis
        Redis::shouldReceive('scan')->once()->andReturn([0, []]);

        $result = $this->service->sync();

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['scanned_keys']);
        $this->assertEquals(0, $result['rows_inserted']);

        // Ensure database remains empty (no manufactured zero rows)
        $this->assertDatabaseCount('tenant_outbound_usage', 0);
    }

    public function test_redis_failure_fails_cleanly_without_fabricating_zeros()
    {
        // Existing confirmed ledger record
        TenantOutboundUsage::create([
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 100,
        ]);

        // Redis connection fails
        Redis::shouldReceive('scan')->once()->andThrow(new Exception('Redis connection refused'));

        $result = $this->service->sync();

        $this->assertFalse($result['success']);
        $this->assertEquals('Redis connection refused', $result['error']);

        // Existing data must remain unchanged
        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 100,
        ]);
        $this->assertDatabaseCount('tenant_outbound_usage', 1);
    }

    public function test_malformed_keys_are_skipped_safely()
    {
        $malformedKeys = [
            "outbound:tenant:abc:recipients:daily:{$this->todayUtc}",
            "outbound:tenant:{$this->tenant->id}:recipients:daily:2026-99-99",
            "outbound:tenant:{$this->tenant->id}:recipients:daily:invalid-date",
            "outbound:tenant:0:recipients:daily:{$this->todayUtc}",
            "outbound:tenant:-5:recipients:daily:{$this->todayUtc}",
            "unrelated:key:format",
        ];

        Redis::shouldReceive('scan')->once()->andReturn([0, $malformedKeys]);

        $result = $this->service->sync();

        $this->assertTrue($result['success']);
        $this->assertEquals(6, $result['scanned_keys']);
        $this->assertEquals(6, $result['malformed_keys']);
        $this->assertEquals(0, $result['processed']);
        $this->assertDatabaseCount('tenant_outbound_usage', 0);
    }

    public function test_invalid_counter_values_are_skipped_safely()
    {
        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}";

        $invalidValues = ['-1', 'abc', '12.34', '99999999999999999999'];

        foreach ($invalidValues as $val) {
            Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
            Redis::shouldReceive('get')->once()->with($key)->andReturn($val);

            $result = $this->service->sync();

            $this->assertTrue($result['success']);
            $this->assertEquals(1, $result['invalid_counters']);
            $this->assertEquals(0, $result['processed']);
            $this->assertDatabaseCount('tenant_outbound_usage', 0);
        }
    }

    public function test_missing_tenant_is_skipped_safely()
    {
        $nonExistentTenantId = 999999;
        $key = "outbound:tenant:{$nonExistentTenantId}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);

        $result = $this->service->sync();

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['missing_tenants']);
        $this->assertEquals(0, $result['processed']);
        $this->assertDatabaseCount('tenant_outbound_usage', 0);
    }

    public function test_sliding_window_filters_outdated_and_future_keys()
    {
        $oldDate = Carbon::now('UTC')->subDays(5)->format('Y-m-d');
        $futureDate = Carbon::now('UTC')->addDays(2)->format('Y-m-d');

        $keys = [
            "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}",
            "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->yesterdayUtc}",
            "outbound:tenant:{$this->tenant->id}:recipients:daily:{$oldDate}",
            "outbound:tenant:{$this->tenant->id}:recipients:daily:{$futureDate}",
        ];

        Redis::shouldReceive('scan')->once()->andReturn([0, $keys]);
        Redis::shouldReceive('get')
            ->with("outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}")
            ->andReturn('10');
        Redis::shouldReceive('get')
            ->with("outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->yesterdayUtc}")
            ->andReturn('20');

        $result = $this->service->sync(null, 2);

        $this->assertTrue($result['success']);
        $this->assertEquals(4, $result['scanned_keys']);
        $this->assertEquals(2, $result['skipped_outside_window']);
        $this->assertEquals(2, $result['processed']);
        $this->assertEquals(2, $result['rows_inserted']);

        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 10,
        ]);
        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->yesterdayUtc,
            'recipient_count' => 20,
        ]);
    }

    public function test_specific_target_date_sync()
    {
        $targetDate = $this->yesterdayUtc;
        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$targetDate}";

        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
        Redis::shouldReceive('get')->once()->with($key)->andReturn('75');

        $result = $this->service->sync($targetDate);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['processed']);
        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $targetDate,
            'recipient_count' => 75,
        ]);
    }

    public function test_dry_run_does_not_modify_database()
    {
        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
        Redis::shouldReceive('get')->once()->with($key)->andReturn('42');

        $result = $this->service->sync(null, 2, true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['dry_run']);
        $this->assertEquals(1, $result['rows_inserted']);

        // Database must remain empty because dry_run was true
        $this->assertDatabaseCount('tenant_outbound_usage', 0);
    }

    public function test_command_execution_success()
    {
        $key = "outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('scan')->once()->andReturn([0, [$key]]);
        Redis::shouldReceive('get')->once()->with($key)->andReturn('15');

        $exitCode = Artisan::call('outbound:usage-sync');

        $this->assertEquals(0, $exitCode);
        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 15,
        ]);
    }

    public function test_command_aborts_cleanly_when_lock_held()
    {
        $lock = Cache::lock('outbound_usage_sync_lock', 600);
        $lock->get();

        Redis::shouldReceive('scan')->never();

        $exitCode = Artisan::call('outbound:usage-sync');

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('already active', Artisan::output());

        $lock->release();
    }

    public function test_command_fails_with_exit_code_1_on_redis_error()
    {
        Redis::shouldReceive('scan')->once()->andThrow(new Exception('Redis unavailable'));

        $exitCode = Artisan::call('outbound:usage-sync');

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Outbound usage synchronization failed', Artisan::output());
    }

    public function test_command_rejects_invalid_date_option()
    {
        $exitCode = Artisan::call('outbound:usage-sync', ['--date' => 'invalid-date']);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Invalid date format', Artisan::output());
    }
}
