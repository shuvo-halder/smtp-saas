<?php

namespace Tests\Feature;

use App\Models\AbuseIncident;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\Abuse\AbuseDetectionService;
use App\Services\Abuse\AbuseIncidentService;
use App\Services\AuditService;
use App\Services\Policy\PolicyDecisionService;
use App\Services\Policy\PolicyRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use ReflectionMethod;
use Tests\TestCase;

class AbuseIncidentLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $deliverabilityOp;
    protected User $supportUser;
    protected User $tenant;
    protected Domain $domain;
    protected Mailbox $mailbox;
    protected Plan $plan;
    protected AbuseIncident $testIncident;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        $this->plan = Plan::create([
            'name'                              => 'Pro Plan',
            'slug'                              => 'pro-plan',
            'price_monthly'                     => 25.00,
            'price_yearly'                      => 250.00,
            'max_domains'                       => 5,
            'max_mailboxes_per_domain'          => 10,
            'storage_mb_per_mailbox'            => 2048,
            'max_aliases_per_domain'            => 5,
            'daily_outbound_recipients'         => 500,
            'mailbox_daily_outbound_recipients' => 100,
            'is_active'                         => true,
        ]);

        $this->superAdmin = User::factory()->superAdmin()->create([
            'email' => 'superadmin@platform.com',
        ]);

        $this->deliverabilityOp = User::factory()->deliverabilityOperator()->create([
            'email' => 'deliverability@platform.com',
        ]);

        $this->supportUser = User::factory()->customerSupport()->create([
            'email' => 'support@platform.com',
        ]);

        $this->tenant = User::factory()->create([
            'email'           => 'tenant@client.com',
            'is_admin'        => false,
            'status'          => 'active',
            'plan_id'         => $this->plan->id,
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
            'password'   => crypt('Secret123!', '$6$rounds=5000$salt$'),
            'quota_mb'   => 2048,
            'is_active'  => true,
        ]);

        $this->testIncident = AbuseIncident::create([
            'tenant_id'        => $this->tenant->id,
            'tenant_email'     => $this->tenant->email,
            'domain_id'        => $this->domain->id,
            'domain_name'      => $this->domain->domain_name,
            'mailbox_id'       => $this->mailbox->id,
            'mailbox_email'    => $this->mailbox->email,
            'incident_type'    => 'smtp_quota_exceeded',
            'severity'         => 'medium',
            'status'           => 'open',
            'detection_source' => 'policy_daemon',
            'summary'          => 'Test baseline incident',
            'occurred_at'      => now(),
        ]);
    }

    // =========================================================================
    // 1. Authorization Boundaries & Access Control
    // =========================================================================

    public function test_unauthenticated_cannot_access_abuse_incidents(): void
    {
        $this->getJson('/api/admin/smtp/incidents')->assertStatus(401);
        $this->getJson("/api/admin/smtp/incidents/{$this->testIncident->id}")->assertStatus(401);
        $this->postJson("/api/admin/smtp/incidents/{$this->testIncident->id}/resolve")->assertStatus(401);
    }

    public function test_ordinary_tenant_cannot_access_abuse_incidents(): void
    {
        $this->actingAs($this->tenant)->getJson('/api/admin/smtp/incidents')->assertStatus(403);
        $this->actingAs($this->tenant)->getJson("/api/admin/smtp/incidents/{$this->testIncident->id}")->assertStatus(403);
        $this->actingAs($this->tenant)->postJson("/api/admin/smtp/incidents/{$this->testIncident->id}/resolve")->assertStatus(403);
    }

    public function test_suspended_admin_cannot_access_abuse_incidents(): void
    {
        $suspendedAdmin = User::factory()->superAdmin()->create(['status' => 'suspended']);

        $this->actingAs($suspendedAdmin)->getJson('/api/admin/smtp/incidents')->assertStatus(403);
        $this->actingAs($suspendedAdmin)->getJson("/api/admin/smtp/incidents/{$this->testIncident->id}")->assertStatus(403);
        $this->actingAs($suspendedAdmin)->postJson("/api/admin/smtp/incidents/{$this->testIncident->id}/resolve")->assertStatus(403);
    }

    public function test_zero_role_admin_cannot_access_abuse_incidents(): void
    {
        $zeroRoleAdmin = User::factory()->withoutRoles()->create();

        $this->actingAs($zeroRoleAdmin)->getJson('/api/admin/smtp/incidents')->assertStatus(403);
        $this->actingAs($zeroRoleAdmin)->getJson("/api/admin/smtp/incidents/{$this->testIncident->id}")->assertStatus(403);
        $this->actingAs($zeroRoleAdmin)->postJson("/api/admin/smtp/incidents/{$this->testIncident->id}/resolve")->assertStatus(403);
    }

    public function test_customer_support_can_view_incidents_but_cannot_resolve(): void
    {
        // Customer Support has admin.smtp.read: can view list and detail
        $this->actingAs($this->supportUser)->getJson('/api/admin/smtp/incidents')->assertStatus(200);
        $this->actingAs($this->supportUser)->getJson("/api/admin/smtp/incidents/{$this->testIncident->id}")->assertStatus(200);

        // Customer Support lacks admin.smtp.mailbox.toggle: cannot resolve
        $this->actingAs($this->supportUser)->postJson("/api/admin/smtp/incidents/{$this->testIncident->id}/resolve", [
            'notes' => 'Resolution attempt by support',
        ])->assertStatus(403);
    }

    public function test_deliverability_operator_can_view_and_resolve_incidents(): void
    {
        // Operator has both permissions
        $this->actingAs($this->deliverabilityOp)->getJson('/api/admin/smtp/incidents')->assertStatus(200);
        $this->actingAs($this->deliverabilityOp)->getJson("/api/admin/smtp/incidents/{$this->testIncident->id}")->assertStatus(200);

        $response = $this->actingAs($this->deliverabilityOp)->postJson("/api/admin/smtp/incidents/{$this->testIncident->id}/resolve", [
            'notes' => 'Investigated and cleared mailbox',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('resolved', $this->testIncident->fresh()->status);
        $this->assertEquals($this->deliverabilityOp->id, $this->testIncident->fresh()->resolved_by);
        $this->assertEquals('Investigated and cleared mailbox', $this->testIncident->fresh()->resolution_notes);
    }

    public function test_super_admin_can_dismiss_incident(): void
    {
        $response = $this->actingAs($this->superAdmin)->postJson("/api/admin/smtp/incidents/{$this->testIncident->id}/resolve", [
            'status' => 'dismissed',
            'notes'  => 'False positive - confirmed marketing blast',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('dismissed', $this->testIncident->fresh()->status);
        $this->assertEquals($this->superAdmin->id, $this->testIncident->fresh()->resolved_by);
        $this->assertEquals('False positive - confirmed marketing blast', $this->testIncident->fresh()->resolution_notes);
    }

    // =========================================================================
    // 2. Incident Ledger Persistence & Snapshot Preservation
    // =========================================================================

    public function test_service_records_incident_and_enforces_idempotency(): void
    {
        $service = app(AbuseIncidentService::class);

        $incident1 = $service->record([
            'tenant_id'        => $this->tenant->id,
            'mailbox_id'       => $this->mailbox->id,
            'incident_type'    => 'high_hard_bounce_rate',
            'severity'         => 'warning',
            'detection_source' => 'log_parser',
            'summary'          => 'Bounce rate exceeded 15%',
            'threshold_value'  => '0.10',
            'observed_value'   => '0.18',
            'evidence'         => ['bounce_rate' => 0.18, 'sample' => 50],
            'idempotency_key'  => 'test:idempotency:key:001',
        ]);

        $this->assertNotNull($incident1);
        $this->assertEquals('client.com', $incident1->domain_name);
        $this->assertEquals('info@client.com', $incident1->mailbox_email);
        $this->assertEquals('tenant@client.com', $incident1->tenant_email);
        $this->assertNotNull($incident1->uuid);

        // Attempting to record again with same idempotency key returns existing record without creating duplicate
        $incident2 = $service->record([
            'tenant_id'        => $this->tenant->id,
            'mailbox_id'       => $this->mailbox->id,
            'incident_type'    => 'high_hard_bounce_rate',
            'severity'         => 'warning',
            'detection_source' => 'log_parser',
            'summary'          => 'Duplicate attempt',
            'idempotency_key'  => 'test:idempotency:key:001',
        ]);

        $this->assertEquals($incident1->id, $incident2->id);
        $this->assertEquals(1, AbuseIncident::where('idempotency_key', 'test:idempotency:key:001')->count());
    }

    public function test_historical_snapshot_preservation_when_entities_deleted(): void
    {
        $service = app(AbuseIncidentService::class);

        $incident = $service->record([
            'tenant_id'        => $this->tenant->id,
            'mailbox_id'       => $this->mailbox->id,
            'incident_type'    => 'smtp_quota_exceeded',
            'severity'         => 'medium',
            'detection_source' => 'policy_daemon',
            'summary'          => 'Mailbox quota breach',
            'idempotency_key'  => 'test:snapshot:preservation:001',
        ]);

        $this->assertEquals('tenant@client.com', $incident->tenant_email);
        $this->assertEquals('client.com', $incident->domain_name);
        $this->assertEquals('info@client.com', $incident->mailbox_email);

        // Explicitly enable foreign keys for SQLite connection to trigger nullOnDelete cascades
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        // Delete mailbox, domain, and tenant
        $this->mailbox->delete();
        $this->domain->delete();
        $this->tenant->delete();

        $fresh = $incident->fresh();

        // Foreign keys become null per nullOnDelete() (or if SQLite ignores pragmas, verify snapshots are always preserved)
        // Historical snapshot columns MUST remain fully preserved
        $this->assertEquals('tenant@client.com', $fresh->tenant_email);
        $this->assertEquals('client.com', $fresh->domain_name);
        $this->assertEquals('info@client.com', $fresh->mailbox_email);
    }

    public function test_evidence_recursive_redaction_sanitizes_credentials(): void
    {
        $service = app(AbuseIncidentService::class);

        $incident = $service->record([
            'tenant_id'        => $this->tenant->id,
            'mailbox_id'       => $this->mailbox->id,
            'incident_type'    => 'suspicious_payload',
            'severity'         => 'critical',
            'detection_source' => 'policy_daemon',
            'summary'          => 'Potentially compromised credentials',
            'evidence'         => [
                'password'       => 'PlainPassword123!',
                'token'          => 'Bearer-super-secret-token',
                'secret_key'     => 'sk_live_123456789',
                'safe_attribute' => 'value123',
                'nested'         => [
                    'authorization' => 'Basic dXNlcjpwYXNz',
                    'count'         => 42,
                ],
            ],
            'idempotency_key'  => 'test:redaction:evidence:001',
        ]);

        $evidence = $incident->fresh()->evidence;

        $this->assertEquals('[REDACTED]', $evidence['password']);
        $this->assertEquals('[REDACTED]', $evidence['token']);
        $this->assertEquals('[REDACTED]', $evidence['secret_key']);
        $this->assertEquals('value123', $evidence['safe_attribute']);
        $this->assertEquals('[REDACTED]', $evidence['nested']['authorization']);
        $this->assertEquals(42, $evidence['nested']['count']);
    }

    // =========================================================================
    // 3. Detector Integration Tests
    // =========================================================================

    public function test_abuse_detection_service_emits_and_persists_incident(): void
    {
        $detectionService = app(AbuseDetectionService::class);

        // Invoke private emitAlert directly via Reflection to test detector-to-ledger persistence cleanly
        $refMethod = new ReflectionMethod(AbuseDetectionService::class, 'emitAlert');
        $refMethod->setAccessible(true);

        $refMethod->invoke($detectionService, 'CONSECUTIVE_HARD_BOUNCES_EXCEEDED', [
            'tenant_id'                => $this->tenant->id,
            'mailbox_id'               => $this->mailbox->id,
            'threshold'                => 10,
            'consecutive_hard_bounces' => 12,
            'date'                     => Carbon::now('UTC')->format('Y-m-d'),
        ]);

        // Assert AbuseIncident record was created
        $incident = AbuseIncident::where('mailbox_id', $this->mailbox->id)
            ->where('incident_type', 'consecutive_hard_bounces_exceeded')
            ->first();

        $this->assertNotNull($incident);
        $this->assertEquals('critical', $incident->severity);
        $this->assertEquals('log_parser', $incident->detection_source);
        $this->assertEquals($this->tenant->id, $incident->tenant_id);
        $this->assertEquals('client.com', $incident->domain_name);
        $this->assertEquals('info@client.com', $incident->mailbox_email);
    }

    public function test_policy_decision_service_records_quota_incident_with_daily_cooldown(): void
    {
        $this->testIncident->delete();
        $policyService = app(PolicyDecisionService::class);

        // Mock Redis for quota check (eval returns 0 for reject) and cooldown (set returns true then false)
        Redis::shouldReceive('eval')->andReturn(0);
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('setex')->andReturn(true);

        // First call: cooldown acquired
        Redis::shouldReceive('set')
            ->once()
            ->andReturn(true);

        $request = PolicyRequest::fromAttributes([
            'request'        => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username'  => 'info@client.com',
            'recipient_count'=> '150',
            'instance'       => 'tx_abuse_test_01',
        ]);

        $response = $policyService->evaluate($request);

        $this->assertEquals('REJECTED_QUOTA', $response->status);
        $this->assertStringContainsString('REJECT 554 5.7.1', $response->action);

        // Verify incident recorded in persistent ledger
        $incident = AbuseIncident::where('mailbox_id', $this->mailbox->id)
            ->where('incident_type', 'smtp_quota_exceeded')
            ->first();

        $this->assertNotNull($incident);
        $this->assertEquals('medium', $incident->severity);
        $this->assertEquals('policy_daemon', $incident->detection_source);
        $this->assertEquals($this->tenant->id, $incident->tenant_id);
        $this->assertEquals('info@client.com', $incident->mailbox_email);

        // Second call: cooldown already active (Redis set NX returns false)
        Redis::shouldReceive('set')
            ->once()
            ->andReturn(false);

        $response2 = $policyService->evaluate($request);
        $this->assertEquals('REJECTED_QUOTA', $response2->status);

        $count = AbuseIncident::where('mailbox_id', $this->mailbox->id)
            ->where('incident_type', 'smtp_quota_exceeded')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_policy_decision_service_fails_safe_when_incident_persistence_fails(): void
    {
        // Mock AbuseIncidentService to throw an exception
        $failingIncidentService = new class extends AbuseIncidentService {
            public function record(array $data): ?AbuseIncident
            {
                throw new \RuntimeException('Database connection lost during incident persistence');
            }
        };

        $this->app->instance(AbuseIncidentService::class, $failingIncidentService);

        $policyService = app(PolicyDecisionService::class);

        Redis::shouldReceive('eval')->andReturn(0);
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('setex')->andReturn(true);
        Redis::shouldReceive('set')->andReturn(true);

        $request = PolicyRequest::fromAttributes([
            'request'        => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username'  => 'info@client.com',
            'recipient_count'=> '150',
            'instance'       => 'tx_abuse_test_02',
        ]);

        // Policy evaluation MUST still succeed with REJECTED_QUOTA and never throw or return DUNNO
        $response = $policyService->evaluate($request);

        $this->assertEquals('REJECTED_QUOTA', $response->status);
        $this->assertStringContainsString('REJECT 554 5.7.1', $response->action);
    }

    // =========================================================================
    // 4. Resolution & Audit Logging
    // =========================================================================

    public function test_incident_resolution_creates_audit_log_record(): void
    {
        $this->actingAs($this->superAdmin)->postJson("/api/admin/smtp/incidents/{$this->testIncident->id}/resolve", [
            'notes' => 'Tenant upgraded to Enterprise plan',
        ])->assertStatus(200);

        // Verify audit log created
        $auditLog = AuditLog::where('action', 'admin.abuse_incident.resolve')
            ->where('entity_type', 'AbuseIncident')
            ->where('entity_id', $this->testIncident->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($this->superAdmin->id, $auditLog->actor_user_id);
        $this->assertEquals(['status' => 'open'], $auditLog->before_state);
        $this->assertEquals('resolved', $auditLog->after_state['status']);
        $this->assertEquals('Tenant upgraded to Enterprise plan', $auditLog->reason);
    }

    public function test_route_model_binding_supports_both_id_and_uuid(): void
    {
        // Integer ID lookup
        $resById = $this->actingAs($this->superAdmin)->getJson("/api/admin/smtp/incidents/{$this->testIncident->id}");
        $resById->assertStatus(200);
        $this->assertEquals($this->testIncident->uuid, $resById->json('data.uuid'));

        // UUID lookup
        $resByUuid = $this->actingAs($this->superAdmin)->getJson("/api/admin/smtp/incidents/{$this->testIncident->uuid}");
        $resByUuid->assertStatus(200);
        $this->assertEquals($this->testIncident->id, $resByUuid->json('data.id'));
    }

    public function test_filtering_and_bounded_pagination(): void
    {
        // Delete baseline incident for clean count
        $this->testIncident->delete();

        for ($i = 1; $i <= 30; $i++) {
            AbuseIncident::create([
                'tenant_id'        => $this->tenant->id,
                'tenant_email'     => $this->tenant->email,
                'domain_id'        => $this->domain->id,
                'domain_name'      => $this->domain->domain_name,
                'mailbox_id'       => $this->mailbox->id,
                'mailbox_email'    => $this->mailbox->email,
                'incident_type'    => $i % 2 === 0 ? 'smtp_quota_exceeded' : 'consecutive_hard_bounces_exceeded',
                'severity'         => $i % 2 === 0 ? 'medium' : 'critical',
                'status'           => $i <= 20 ? 'open' : 'resolved',
                'detection_source' => 'policy_daemon',
                'summary'          => "Incident summary #{$i}",
                'occurred_at'      => now()->subMinutes($i),
            ]);
        }

        // Test pagination bounded to 15 by default
        $res = $this->actingAs($this->superAdmin)->getJson('/api/admin/smtp/incidents');
        $res->assertStatus(200);
        $this->assertCount(15, $res->json('data'));

        // Test filter by status=resolved
        $resResolved = $this->actingAs($this->superAdmin)->getJson('/api/admin/smtp/incidents?status=resolved');
        $resResolved->assertStatus(200);
        $this->assertCount(10, $resResolved->json('data'));

        // Test filter by severity=critical
        $resCritical = $this->actingAs($this->superAdmin)->getJson('/api/admin/smtp/incidents?severity=critical');
        $resCritical->assertStatus(200);
        $this->assertCount(15, $resCritical->json('data'));

        // Test filter by incident_type
        $resType = $this->actingAs($this->superAdmin)->getJson('/api/admin/smtp/incidents?incident_type=smtp_quota_exceeded');
        $resType->assertStatus(200);
        $this->assertCount(15, $resType->json('data'));
    }
}
