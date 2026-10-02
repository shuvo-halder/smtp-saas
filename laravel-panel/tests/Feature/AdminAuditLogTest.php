<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenant;
    protected Domain $domain;
    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create([
            'name'                     => 'Pro Plan',
            'slug'                     => 'pro-plan',
            'price_monthly'            => 1000,
            'price_yearly'             => 10000,
            'max_domains'              => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox'   => 2048,
            'max_aliases_per_domain'   => 5,
            'daily_outbound_recipients' => 500,
            'mailbox_daily_outbound_recipients' => 100,
        ]);

        $this->admin = User::factory()->superAdmin()->create([
            'email'    => 'admin@platform.com',
            'is_admin' => true,
            'status'   => 'active',
            'plan_id'  => $plan->id,
        ]);

        $this->tenant = User::factory()->create([
            'email'           => 'tenant@client.com',
            'is_admin'        => false,
            'status'          => 'active',
            'plan_id'         => $plan->id,
            'plan_expires_at' => Carbon::now()->addMonth(),
        ]);

        $this->domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'client.com',
            'status'      => 'active',
            'mx_verified' => true,
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id'  => $this->domain->id,
            'local_part' => 'info',
            'email'      => 'info@client.com',
            'password'   => '$6$salt$initialhash',
            'quota_mb'   => 2048,
            'is_active'  => true,
        ]);
    }

    public function test_unauthenticated_cannot_access_audit_logs()
    {
        $this->getJson('/api/admin/audit-logs')->assertStatus(401);
        $this->getJson('/api/admin/audit-logs/1')->assertStatus(401);
    }

    public function test_non_admin_tenant_forbidden_from_audit_logs()
    {
        $this->actingAs($this->tenant)->getJson('/api/admin/audit-logs')->assertStatus(403);
        $this->actingAs($this->tenant)->getJson('/api/admin/audit-logs/1')->assertStatus(403);
    }

    public function test_no_audit_mutation_routes_exist()
    {
        // Assert that the audit ledger API is strictly read-only (no POST/PUT/PATCH/DELETE)
        $this->actingAs($this->admin)->postJson('/api/admin/audit-logs', ['action' => 'fake'])->assertStatus(405);
        $this->actingAs($this->admin)->putJson('/api/admin/audit-logs/1', ['action' => 'fake'])->assertStatus(405);
        $this->actingAs($this->admin)->patchJson('/api/admin/audit-logs/1', ['action' => 'fake'])->assertStatus(405);
        $this->actingAs($this->admin)->deleteJson('/api/admin/audit-logs/1')->assertStatus(405);
    }

    public function test_admin_can_list_audit_logs_with_bounded_pagination()
    {
        for ($i = 1; $i <= 60; $i++) {
            AuditLog::create([
                'actor_user_id' => $this->admin->id,
                'actor_email'   => $this->admin->email,
                'action'        => "test.action_{$i}",
                'entity_type'   => 'TestEntity',
                'entity_id'     => $i,
                'created_at'    => Carbon::now()->subMinutes(60 - $i),
            ]);
        }

        // Default pagination: 15 items
        $response = $this->actingAs($this->admin)->getJson('/api/admin/audit-logs');
        $response->assertStatus(200);
        $this->assertCount(15, $response->json('data'));

        // Max page size bound: requesting 100 must be clamped to 50
        $responseMax = $this->actingAs($this->admin)->getJson('/api/admin/audit-logs?per_page=100');
        $responseMax->assertStatus(200);
        $this->assertCount(50, $responseMax->json('data'));
        $this->assertEquals(50, $responseMax->json('meta.per_page'));

        // Newest-first ordering (id 60 should be first)
        $this->assertEquals(60, $responseMax->json('data.0.id'));
    }

    public function test_admin_can_filter_audit_logs()
    {
        AuditLog::create([
            'actor_user_id' => $this->admin->id,
            'actor_email'   => $this->admin->email,
            'action'        => 'mailbox_toggle',
            'entity_type'   => 'Mailbox',
            'entity_id'     => 101,
            'reason'        => 'Security freeze',
            'created_at'    => Carbon::now()->subDays(2),
        ]);

        AuditLog::create([
            'actor_user_id' => $this->admin->id,
            'actor_email'   => $this->admin->email,
            'action'        => 'mailbox_password_reset',
            'entity_type'   => 'Mailbox',
            'entity_id'     => 102,
            'reason'        => 'Requested by user',
            'created_at'    => Carbon::now()->subDay(),
        ]);

        // Filter by action
        $resAction = $this->actingAs($this->admin)->getJson('/api/admin/audit-logs?action=mailbox_toggle');
        $resAction->assertStatus(200);
        $this->assertCount(1, $resAction->json('data'));
        $this->assertEquals('mailbox_toggle', $resAction->json('data.0.action'));

        // Filter by entity_id
        $resEntity = $this->actingAs($this->admin)->getJson('/api/admin/audit-logs?entity_id=102');
        $resEntity->assertStatus(200);
        $this->assertCount(1, $resEntity->json('data'));
        $this->assertEquals(102, $resEntity->json('data.0.entity_id'));

        // Search by reason
        $resSearch = $this->actingAs($this->admin)->getJson('/api/admin/audit-logs?search=freeze');
        $resSearch->assertStatus(200);
        $this->assertCount(1, $resSearch->json('data'));
        $this->assertEquals('Security freeze', $resSearch->json('data.0.reason'));
    }

    public function test_admin_can_view_single_audit_log_detail()
    {
        $log = AuditLog::create([
            'actor_user_id' => $this->admin->id,
            'actor_email'   => $this->admin->email,
            'action'        => 'mailbox_toggle',
            'entity_type'   => 'Mailbox',
            'entity_id'     => $this->mailbox->id,
            'before_state'  => ['is_active' => true],
            'after_state'   => ['is_active' => false],
            'reason'        => 'Investigation containment',
            'ip_address'    => '10.0.0.1',
            'user_agent'    => 'AuditAgent/1.0',
            'request_id'    => 'trace-999',
            'created_at'    => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/audit-logs/{$log->id}");
        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id'            => $log->id,
                    'actor_user_id' => $this->admin->id,
                    'actor_email'   => $this->admin->email,
                    'action'        => 'mailbox_toggle',
                    'entity_type'   => 'Mailbox',
                    'entity_id'     => $this->mailbox->id,
                    'before_state'  => ['is_active' => true],
                    'after_state'   => ['is_active' => false],
                    'reason'        => 'Investigation containment',
                    'ip_address'    => '10.0.0.1',
                    'request_id'    => 'trace-999',
                ],
            ]);

        // Non-existent ID returns 404
        $this->actingAs($this->admin)->getJson('/api/admin/audit-logs/99999')->assertStatus(404);
    }

    public function test_step_16a_mailbox_toggle_persists_audit_log()
    {
        $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle", [
            'reason' => 'Compromised credentials suspected',
        ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'mailbox_toggle',
            'entity_type'   => 'Mailbox',
            'entity_id'     => $this->mailbox->id,
            'actor_user_id' => $this->admin->id,
            'reason'        => 'Compromised credentials suspected',
        ]);

        $log = AuditLog::where('action', 'mailbox_toggle')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals(['is_active' => true], $log->before_state);
        $this->assertEquals(['is_active' => false], $log->after_state);
    }

    public function test_step_16a_password_reset_redaction_in_both_audit_destinations()
    {
        $testPassword = 'KnownTestPassword#1234!';

        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-password", [
            'password' => $testPassword,
            'reason'   => 'Admin initiated password update',
        ]);

        // 1. API response contains the expected one-time new_password
        $response->assertStatus(200);
        $this->assertEquals($testPassword, $response->json('new_password'));
        $this->assertEquals($this->mailbox->id, $response->json('mailbox_id'));

        // 2. audit_logs does NOT contain the password or hash
        $log = AuditLog::where('action', 'mailbox_password_reset')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals(['password_reset' => false], $log->before_state);
        $this->assertEquals(['password_reset' => true], $log->after_state);

        $jsonAudit = json_encode($log->toArray());
        $this->assertStringNotContainsString($testPassword, $jsonAudit);
        $this->assertStringNotContainsString('$6$', $jsonAudit);

        // 3. admin-smtp.log does NOT contain the password or hash
        $todayLog = storage_path('logs/admin-smtp-' . Carbon::now()->format('Y-m-d') . '.log');
        if (File::exists($todayLog)) {
            $logContent = File::get($todayLog);
            $this->assertStringNotContainsString($testPassword, $logContent);
            $this->assertStringNotContainsString('$6$', $logContent);
            $this->assertStringContainsString('mailbox_password_reset', $logContent);
        }
    }

    public function test_step_16a_bounce_reset_persists_audit_log()
    {
        $key = "outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard";

        Redis::shouldReceive('get')
            ->once()
            ->with($key)
            ->andReturn('15');

        Redis::shouldReceive('del')
            ->once()
            ->with($key)
            ->andReturn(1);

        $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-bounces", [
            'reason' => 'Delist approved after warmup period',
        ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'mailbox_bounce_reset',
            'entity_type'   => 'Mailbox',
            'entity_id'     => $this->mailbox->id,
            'actor_user_id' => $this->admin->id,
            'reason'        => 'Delist approved after warmup period',
        ]);

        $log = AuditLog::where('action', 'mailbox_bounce_reset')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals(['consecutive_hard_bounces' => 15], $log->before_state);
        $this->assertEquals(['consecutive_hard_bounces' => 0], $log->after_state);
    }

    public function test_fail_safe_behavior_when_audit_persistence_fails()
    {
        // Bind an AuditService that simulates database failure on record()
        $failingAuditService = new class extends AuditService {
            public function record(
                string $action,
                string $entityType,
                int|string|null $entityId,
                mixed $before = null,
                mixed $after = null,
                ?User $actor = null,
                ?string $reason = null,
                ?\Illuminate\Http\Request $request = null
            ): ?AuditLog {
                // Simulates catch block returning null
                return null;
            }
        };

        $this->app->instance(AuditService::class, $failingAuditService);

        // Disabling mailbox should succeed regardless of audit persistence failure
        $response = $this->actingAs($this->admin)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle", [
            'reason' => 'Emergency deactivation under audit degradation',
        ]);

        $response->assertStatus(200);
        $this->assertFalse($this->mailbox->fresh()->is_active);
    }
}
