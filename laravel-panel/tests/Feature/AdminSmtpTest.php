<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AdminSmtpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;
    private Plan $plan;
    private Domain $domain;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name' => 'Pro Business',
            'slug' => 'pro-business',
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox' => 2048,
            'max_aliases_per_domain' => 20,
            'daily_outbound_recipients' => 500,
            'mailbox_daily_outbound_recipients' => 100,
            'price_monthly' => 20,
            'price_yearly' => 200,
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'status' => 'active',
            'plan_id' => $this->plan->id,
            'plan_expires_at' => Carbon::now()->addMonth(),
        ]);

        $this->user = User::factory()->create([
            'is_admin' => false,
            'status' => 'active',
            'plan_id' => $this->plan->id,
            'plan_expires_at' => Carbon::now()->addMonth(),
        ]);

        $this->domain = Domain::create([
            'user_id' => $this->user->id,
            'domain_name' => 'clientdomain.com',
            'status' => 'active',
            'mx_verified' => true,
            'spf_verified' => true,
            'dkim_verified' => true,
            'dmarc_verified' => true,
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id' => $this->domain->id,
            'local_part' => 'sales',
            'email' => 'sales@clientdomain.com',
            'password' => crypt('Secret123!', '$6$' . \Illuminate\Support\Str::random(16) . '$'),
            'quota_mb' => 2048,
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_smtp_endpoints()
    {
        $this->getJson('/api/admin/smtp/overview')->assertStatus(401);
        $this->getJson('/api/admin/smtp/tenants')->assertStatus(401);
        $this->getJson('/api/admin/smtp/mailboxes')->assertStatus(401);
        $this->getJson('/api/admin/smtp/abuse')->assertStatus(401);
        $this->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle")->assertStatus(401);
        $this->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-bounces")->assertStatus(401);
        $this->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-password")->assertStatus(401);
    }

    public function test_non_admin_tenant_forbidden()
    {
        $this->actingAs($this->user)->getJson('/api/admin/smtp/overview')->assertStatus(403);
        $this->actingAs($this->user)->getJson('/api/admin/smtp/tenants')->assertStatus(403);
        $this->actingAs($this->user)->getJson('/api/admin/smtp/mailboxes')->assertStatus(403);
        $this->actingAs($this->user)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle")->assertStatus(403);
    }

    public function test_admin_can_view_smtp_overview()
    {
        Redis::shouldReceive('ping')->atLeast()->once()->andReturn(true);
        Redis::shouldReceive('mget')->andReturn([
            100, 5, 2, // tenant 1
            0, 0, 0,   // admin tenant
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/smtp/overview');
        $response->assertStatus(200)
            ->assertJson([
                'telemetry_available'      => true,
                'cluster_recipients_today' => 100,
                'cluster_hard_bounces_today' => 5,
                'cluster_soft_bounces_today' => 2,
                'cluster_bounce_rate'      => 0.05,
            ])
            ->assertJsonStructure([
                'telemetry_available',
                'cluster_recipients_today',
                'cluster_hard_bounces_today',
                'cluster_soft_bounces_today',
                'cluster_bounce_rate',
                'mail_queue_size',
                'total_tenants',
                'total_mailboxes',
                'active_abuse_warnings_count',
                'checked_at',
            ]);
    }

    public function test_overview_handles_zero_recipients_without_division_by_zero()
    {
        Redis::shouldReceive('ping')->atLeast()->once()->andReturn(true);
        Redis::shouldReceive('mget')->andReturn([0, 0, 0, 0, 0, 0]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/smtp/overview');
        $response->assertStatus(200)
            ->assertJson([
                'telemetry_available'      => true,
                'cluster_recipients_today' => 0,
                'cluster_hard_bounces_today' => 0,
                'cluster_soft_bounces_today' => 0,
                'cluster_bounce_rate'      => null,
            ]);
    }

    public function test_overview_reports_telemetry_unavailable_when_redis_fails()
    {
        Redis::shouldReceive('ping')->once()->andThrow(new Exception('Redis connection failed'));

        $response = $this->actingAs($this->admin)->getJson('/api/admin/smtp/overview');
        $response->assertStatus(200)
            ->assertJson([
                'telemetry_available'      => false,
                'cluster_recipients_today' => null,
                'cluster_hard_bounces_today' => null,
                'cluster_soft_bounces_today' => null,
                'cluster_bounce_rate'      => null,
                'active_abuse_warnings_count' => null,
            ]);
    }

    public function test_admin_can_list_tenants_with_smtp_telemetry()
    {
        Redis::shouldReceive('ping')->atLeast()->once()->andReturn(true);
        Redis::shouldReceive('mget')->andReturn([
            0, 0, 0,    // first tenant
            80, 2, 1,   // second tenant (2 hard bounces / 80 = 0.025 rate -> healthy)
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/smtp/tenants');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $tenantEntry = collect($data)->firstWhere('id', $this->user->id);
        $this->assertNotNull($tenantEntry);
        $this->assertEquals(500, $tenantEntry['daily_quota']);
        $this->assertEquals(80, $tenantEntry['today_recipients']);
        $this->assertEquals(2, $tenantEntry['today_hard_bounces']);
        $this->assertEquals(1, $tenantEntry['today_soft_bounces']);
        $this->assertEquals(0.025, $tenantEntry['bounce_rate']);
        $this->assertEquals('healthy', $tenantEntry['abuse_status']);
    }

    public function test_admin_can_view_tenant_detail_with_30_day_history()
    {
        $yesterday = Carbon::now('UTC')->subDay()->format('Y-m-d');

        TenantOutboundUsage::create([
            'user_id' => $this->user->id,
            'usage_date' => $yesterday,
            'recipient_count' => 120,
        ]);

        Redis::shouldReceive('ping')->atLeast()->once()->andReturn(true);
        Redis::shouldReceive('mget')->andReturn([45, 2, 0, null, null]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/smtp/tenants/{$this->user->id}");
        $response->assertStatus(200)
            ->assertJson([
                'tenant' => [
                    'id' => $this->user->id,
                    'email' => $this->user->email,
                    'daily_quota' => 500,
                ],
                'telemetry' => [
                    'available' => true,
                    'recipients' => 45,
                    'hard_bounces' => 2,
                ],
            ]);

        $history = $response->json('historical_usage');
        $this->assertCount(1, $history);
        $this->assertEquals($yesterday, $history[0]['usage_date']);
        $this->assertEquals(120, $history[0]['recipient_count']);
    }

    public function test_admin_can_list_mailboxes_without_exposing_credentials()
    {
        Redis::shouldReceive('ping')->atLeast()->once()->andReturn(true);
        Redis::shouldReceive('mget')->andReturn([25, 1, 0, 3]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/smtp/mailboxes');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $mb = collect($data)->firstWhere('id', $this->mailbox->id);
        $this->assertNotNull($mb);
        $this->assertEquals('sales@clientdomain.com', $mb['email']);
        $this->assertTrue($mb['is_active']);
        $this->assertEquals(25, $mb['today_recipients']);
        $this->assertEquals(3, $mb['consecutive_hard_bounces']);

        // Assert passwords and hashes are completely omitted
        $this->assertArrayNotHasKey('password', $mb);
        $this->assertArrayNotHasKey('password_hash', $mb);
    }

    public function test_admin_can_disable_active_mailbox()
    {
        $this->assertTrue($this->mailbox->is_active);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle", [
            'reason' => 'Administrative containment',
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('is_active'));

        $this->mailbox->refresh();
        $this->assertFalse($this->mailbox->is_active);
    }

    public function test_admin_can_enable_mailbox_under_active_tenant_and_domain()
    {
        $this->mailbox->update(['is_active' => false]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle", [
            'reason' => 'Restoring service',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('is_active'));

        $this->mailbox->refresh();
        $this->assertTrue($this->mailbox->is_active);
    }

    public function test_cannot_enable_mailbox_under_suspended_domain()
    {
        $this->mailbox->update(['is_active' => false]);
        $this->domain->update(['status' => 'suspended']);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle");
        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot enable mailbox belonging to an inactive or suspended domain.',
            ]);

        $this->mailbox->refresh();
        $this->assertFalse($this->mailbox->is_active);
    }

    public function test_cannot_enable_mailbox_under_suspended_tenant()
    {
        $this->mailbox->update(['is_active' => false]);
        $this->user->update(['status' => 'suspended']);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle");
        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot enable mailbox belonging to an inactive, suspended, or expired tenant.',
            ]);

        $this->mailbox->refresh();
        $this->assertFalse($this->mailbox->is_active);
    }

    public function test_cannot_enable_mailbox_under_expired_tenant()
    {
        $this->mailbox->update(['is_active' => false]);
        $this->user->update(['plan_expires_at' => Carbon::now()->subDay()]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle");
        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot enable mailbox belonging to an inactive, suspended, or expired tenant.',
            ]);

        $this->mailbox->refresh();
        $this->assertFalse($this->mailbox->is_active);
    }

    public function test_admin_can_reset_consecutive_bounces()
    {
        $key = "outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard";

        Redis::shouldReceive('get')
            ->once()
            ->with($key)
            ->andReturn('18');

        Redis::shouldReceive('del')
            ->once()
            ->with($key)
            ->andReturn(1);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-bounces", [
            'reason' => 'User cleaned mailing list',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'mailbox_id' => $this->mailbox->id,
                'consecutive_hard_bounces' => 0,
            ]);
    }

    public function test_consecutive_bounce_reset_is_idempotent()
    {
        $key = "outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard";

        Redis::shouldReceive('get')
            ->once()
            ->with($key)
            ->andReturn(null);

        Redis::shouldReceive('del')
            ->once()
            ->with($key)
            ->andReturn(0);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-bounces");
        $response->assertStatus(200)
            ->assertJson([
                'consecutive_hard_bounces' => 0,
            ]);
    }

    public function test_admin_can_reset_mailbox_password_with_sha512_crypt()
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-password", [
            'password' => 'NewSecureP@ss123',
            'reason' => 'User forgot credentials',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'mailbox_id' => $this->mailbox->id,
                'email' => $this->mailbox->email,
                'new_password' => 'NewSecureP@ss123',
            ]);

        $this->mailbox->refresh();
        $hash = $this->mailbox->password;

        // Verify hash format is SHA512-CRYPT ($6$...)
        $this->assertStringStartsWith('$6$', $hash);
        $this->assertEquals($hash, crypt('NewSecureP@ss123', $hash));
    }

    public function test_admin_can_view_active_abuse_warnings()
    {
        Redis::shouldReceive('ping')->atLeast()->once()->andReturn(true);
        // mget for active tenants
        Redis::shouldReceive('mget')->andReturn(
            [0, 0, 50, 10], // tenants: admin(0,0), user(50 recipients, 10 hard bounces -> 20% bounce rate)
            [16]            // mailbox: 16 consecutive bounces
        );

        $response = $this->actingAs($this->admin)->getJson('/api/admin/smtp/abuse');
        $response->assertStatus(200)
            ->assertJson(['telemetry_available' => true]);

        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);

        $rateWarning = collect($warnings)->firstWhere('alert_type', 'HIGH_HARD_BOUNCE_RATE');
        $this->assertNotNull($rateWarning);
        $this->assertEquals($this->user->id, $rateWarning['entity_id']);

        $consecutiveWarning = collect($warnings)->firstWhere('alert_type', 'CONSECUTIVE_HARD_BOUNCES');
        $this->assertNotNull($consecutiveWarning);
        $this->assertEquals($this->mailbox->id, $consecutiveWarning['entity_id']);
    }

    public function test_step_13_14_15_keys_remain_isolated()
    {
        // Assert that during bounce reset, ONLY outbound:abuse:mailbox:{id}:consecutive_hard is targeted
        $consecutiveKey = "outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard";

        Redis::shouldReceive('get')
            ->once()
            ->with($consecutiveKey)
            ->andReturn('5');

        Redis::shouldReceive('del')
            ->once()
            ->with($consecutiveKey)
            ->andReturn(1);

        // Explicitly forbid deleting quota or seen keys
        Redis::shouldReceive('del')
            ->withArgs(fn($k) => str_starts_with($k, 'outbound:tenant:') || str_starts_with($k, 'outbound:abuse:seen:'))
            ->never();

        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-bounces");
        $response->assertStatus(200);

        // Verify historical usage table in MariaDB was not mutated
        $this->assertDatabaseCount('tenant_outbound_usage', 0);
    }

    public function test_abuse_warnings_chunks_mailbox_mget_lookups_bounded_to_100_keys()
    {
        // Setup 250 active mailboxes total (1 already created in setUp, create 249 more)
        $extraMailboxes = [];
        for ($i = 2; $i <= 250; $i++) {
            $extraMailboxes[] = [
                'domain_id'  => $this->domain->id,
                'local_part' => "user{$i}",
                'email'      => "user{$i}@clientdomain.com",
                'password'   => '$6$dummyhash',
                'quota_mb'   => 1024,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Mailbox::insert($extraMailboxes);

        Redis::shouldReceive('ping')->atLeast()->once()->andReturn(true);

        // Active tenants mget: admin and user (length 4) -> 0
        Redis::shouldReceive('mget')
            ->once()
            ->withArgs(function ($keys) {
                return count($keys) === 4 && str_starts_with($keys[0], 'outbound:tenant:');
            })
            ->andReturn([0, 0, 0, 0]);

        // Expect exactly 3 mailbox MGET calls with bounded batch sizes (100, 100, 50)
        $recordedBatchSizes = [];

        Redis::shouldReceive('mget')
            ->times(3)
            ->withArgs(function ($keys) use (&$recordedBatchSizes) {
                if (str_starts_with($keys[0], 'outbound:abuse:mailbox:')) {
                    $recordedBatchSizes[] = count($keys);
                    return count($keys) <= 100;
                }
                return false;
            })
            ->andReturnUsing(function ($keys) {
                $result = array_fill(0, count($keys), 0);
                if ($keys[0] === "outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard") {
                    $result[0] = 18;
                }
                return $result;
            });

        $response = $this->actingAs($this->admin)->getJson('/api/admin/smtp/abuse');
        $response->assertStatus(200);

        // Verify bounded batch sizes: exactly 100, 100, 50
        $this->assertEquals([100, 100, 50], $recordedBatchSizes);

        // Verify warnings remain functionally identical
        $warnings = $response->json('warnings');
        $this->assertCount(1, $warnings);
        $this->assertEquals('CONSECUTIVE_HARD_BOUNCES', $warnings[0]['alert_type']);
        $this->assertEquals($this->mailbox->id, $warnings[0]['entity_id']);
        $this->assertEquals(18, $warnings[0]['current_value']);
    }
}


