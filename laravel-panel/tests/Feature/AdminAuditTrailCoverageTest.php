<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\Abuse\AbuseIncidentService;
use App\Services\AuditService;
use App\Services\PostfixService;
use App\Services\SecurityAuditLogger;
use App\Services\SuperAdminGovernanceService;
use Carbon\Carbon;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AdminAuditTrailCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $tenant;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $mockPostfix = Mockery::mock(PostfixService::class);
        $mockPostfix->shouldReceive('addDomain')->andReturn(true);
        $mockPostfix->shouldReceive('removeDomain')->andReturn(true);
        $mockPostfix->shouldReceive('addMailbox')->andReturn(true);
        $mockPostfix->shouldReceive('removeMailbox')->andReturn(true);
        $this->app->instance(PostfixService::class, $mockPostfix);

        $this->plan = Plan::create([
            'name'                              => 'Enterprise Pro',
            'slug'                              => 'enterprise-pro',
            'price_monthly'                     => 49.99,
            'price_yearly'                      => 499.99,
            'max_domains'                       => 10,
            'max_mailboxes_per_domain'          => 50,
            'storage_mb_per_mailbox'            => 5120,
            'max_aliases_per_domain'            => 20,
            'daily_outbound_recipients'         => 2000,
            'mailbox_daily_outbound_recipients' => 500,
            'is_active'                         => true,
        ]);

        $this->superAdmin = User::factory()->superAdmin()->create([
            'email'    => 'security-admin@emailsaas.local',
            'is_admin' => true,
            'status'   => 'active',
            'plan_id'  => $this->plan->id,
        ]);

        $this->tenant = User::factory()->create([
            'email'           => 'tenant-owner@example.org',
            'is_admin'        => false,
            'status'          => 'active',
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => Carbon::now()->addYear(),
        ]);
    }

    public function test_login_and_logout_events_generate_audit_logs(): void
    {
        event(new Login('web', $this->tenant, false));

        $loginLog = AuditLog::where('action', 'auth.login')
            ->where('actor_user_id', $this->tenant->id)
            ->first();

        $this->assertNotNull($loginLog);
        $this->assertEquals($this->tenant->email, $loginLog->actor_email);
        $this->assertEquals('User', $loginLog->entity_type);
        $this->assertEquals($this->tenant->id, $loginLog->entity_id);
        $this->assertEquals('success', $loginLog->after_state['outcome'] ?? null);

        event(new Logout('web', $this->tenant));

        $logoutLog = AuditLog::where('action', 'auth.logout')
            ->where('actor_user_id', $this->tenant->id)
            ->first();

        $this->assertNotNull($logoutLog);
        $this->assertEquals($this->tenant->email, $logoutLog->actor_email);
        $this->assertEquals('success', $logoutLog->after_state['outcome'] ?? null);
    }

    public function test_failed_login_event_records_attempted_email_and_strictly_excludes_password(): void
    {
        $leakedSecret = 'SuperSecretPlaintextPassword!999';

        event(new Failed('web', null, [
            'email'    => 'unauthorized-intruder@attack.org',
            'password' => $leakedSecret,
        ]));

        $failedLog = AuditLog::where('action', 'auth.failed')->latest('id')->first();

        $this->assertNotNull($failedLog);
        $this->assertNull($failedLog->actor_user_id);
        $this->assertEquals('unauthorized-intruder@attack.org', $failedLog->actor_email);
        $this->assertEquals('unauthorized-intruder@attack.org', $failedLog->after_state['attempted_email'] ?? null);
        $this->assertEquals('failure', $failedLog->after_state['outcome'] ?? null);

        // Assert strictly NO credentials or secret passwords exist anywhere in the serialized log
        $serialized = json_encode($failedLog->toArray());
        $this->assertStringNotContainsString($leakedSecret, $serialized);
        $this->assertStringNotContainsString('password', $serialized);
    }

    public function test_security_authorization_denials_are_recorded_and_fail_closed(): void
    {
        SecurityAuditLogger::logDenial(
            request: null,
            reason: 'Test authorization failure to critical endpoint',
            requiredPermission: 'admin.audit_logs.read',
            targetType: 'audit_log',
            targetId: '42',
            extraContext: ['probe' => 'unauthorized_scanner']
        );

        $denialLog = AuditLog::where('action', 'security.authorization_denied')->latest('id')->first();

        $this->assertNotNull($denialLog);
        $this->assertEquals('audit_log', $denialLog->entity_type);
        $this->assertEquals('42', (string) $denialLog->entity_id);
        $this->assertEquals('admin.audit_logs.read', $denialLog->after_state['required_permission'] ?? null);
        $this->assertEquals('denial', $denialLog->after_state['outcome'] ?? null);
    }

    public function test_tenant_and_mail_administration_actions_record_audit_logs(): void
    {
        // 1. Domain Create
        $domainResponse = $this->actingAs($this->tenant)->postJson('/api/domains', [
            'domain_name' => 'client-domain.org',
        ]);
        $domainResponse->assertStatus(201);
        $domainId = $domainResponse->json('data.id') ?? $domainResponse->json('id');

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'domain.create',
            'entity_type'   => 'Domain',
            'entity_id'     => $domainId,
            'actor_user_id' => $this->tenant->id,
        ]);

        // 2. Mailbox Create
        $mailboxResponse = $this->actingAs($this->tenant)->postJson("/api/domains/{$domainId}/mailboxes", [
            'local_part' => 'support',
            'password'   => 'SecureTestPass#123!',
            'quota_mb'   => 1024,
        ]);
        $mailboxResponse->assertStatus(201);
        $mailboxId = $mailboxResponse->json('data.id') ?? $mailboxResponse->json('id');

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'mailbox.create',
            'entity_type'   => 'Mailbox',
            'entity_id'     => $mailboxId,
            'actor_user_id' => $this->tenant->id,
        ]);

        // Verify password is NOT in mailbox.create after_state
        $createLog = AuditLog::where('action', 'mailbox.create')->where('entity_id', $mailboxId)->first();
        $this->assertStringNotContainsString('SecureTestPass#123!', json_encode($createLog->toArray()));

        // 3. Mailbox Change Password
        $pwResponse = $this->actingAs($this->tenant)->postJson("/api/mailboxes/{$mailboxId}/change-password", [
            'password' => 'UpdatedSecretPass#456!',
        ]);
        $pwResponse->assertStatus(200);

        $pwLog = AuditLog::where('action', 'mailbox.change_password')->where('entity_id', $mailboxId)->first();
        $this->assertNotNull($pwLog);
        $this->assertStringNotContainsString('UpdatedSecretPass#456!', json_encode($pwLog->toArray()));
        $this->assertEquals('success', $pwLog->after_state['outcome'] ?? null);

        // 4. Mailbox Toggle
        $toggleResponse = $this->actingAs($this->tenant)->postJson("/api/mailboxes/{$mailboxId}/toggle");
        $toggleResponse->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'mailbox.toggle',
            'entity_type' => 'Mailbox',
            'entity_id'   => $mailboxId,
        ]);

        // 5. Mailbox Delete
        $deleteMailboxResponse = $this->actingAs($this->tenant)->deleteJson("/api/mailboxes/{$mailboxId}");
        $this->assertContains($deleteMailboxResponse->status(), [200, 204]);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'mailbox.delete',
            'entity_type' => 'Mailbox',
            'entity_id'   => $mailboxId,
        ]);

        // 6. Domain Delete
        $deleteDomainResponse = $this->actingAs($this->tenant)->deleteJson("/api/domains/{$domainId}");
        $this->assertContains($deleteDomainResponse->status(), [200, 204]);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'domain.delete',
            'entity_type' => 'Domain',
            'entity_id'   => $domainId,
        ]);
    }

    public function test_super_admin_governance_role_assignment_and_demotion_audited(): void
    {
        $governance = app(SuperAdminGovernanceService::class);

        // Target user to promote
        $target = User::factory()->create(['status' => 'active', 'is_admin' => true]);

        // Assign Super Admin
        $governance->assignSuperAdmin($target);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'admin.roles.assign',
            'entity_type' => 'user',
            'entity_id'   => (string) $target->id,
        ]);

        // Demote Super Admin (superAdmin still exists so remaining count >= 1)
        $governance->demoteSuperAdmin($target);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'admin.roles.demote',
            'entity_type' => 'user',
            'entity_id'   => (string) $target->id,
        ]);
    }

    public function test_sensitive_data_sanitization_recursively_redacts_keys_and_jwt_tokens(): void
    {
        $auditService = app(AuditService::class);

        $dummyJwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

        $payload = [
            'username'               => 'admin_operator',
            'db_password'            => 'TopSecretDbPassword123!',
            'encryption_key'         => 'AES256GCMSecretKeyHere!',
            'backup_encryption_key'  => 'DisasterRecoveryKey!',
            'passphrase'             => 'RecoveryPassphrase!',
            'authorization'          => 'Bearer my-token-12345',
            'bearer'                 => 'bearer-secret-token',
            'cookie'                 => 'session_id=abcdef123456789',
            'card_number'            => '4111222233334444',
            'nested'                 => [
                'jwt_signature' => $dummyJwt,
                'api_secret'    => 'ApiSecretKeyABC',
                'safe_field'    => 'normal_value',
                'deep_nested'   => [
                    'password' => 'DeepPasswordSecret!',
                ],
            ],
        ];

        $sanitized = $auditService->sanitizeState($payload);

        $this->assertEquals('admin_operator', $sanitized['username']);
        $this->assertEquals('[REDACTED]', $sanitized['db_password']);
        $this->assertEquals('[REDACTED]', $sanitized['encryption_key']);
        $this->assertEquals('[REDACTED]', $sanitized['backup_encryption_key']);
        $this->assertEquals('[REDACTED]', $sanitized['passphrase']);
        $this->assertEquals('[REDACTED]', $sanitized['authorization']);
        $this->assertEquals('[REDACTED]', $sanitized['bearer']);
        $this->assertEquals('[REDACTED]', $sanitized['cookie']);
        $this->assertEquals('[REDACTED]', $sanitized['card_number']);

        $this->assertEquals('normal_value', $sanitized['nested']['safe_field']);
        $this->assertEquals('[REDACTED]', $sanitized['nested']['jwt_signature']);
        $this->assertEquals('[REDACTED]', $sanitized['nested']['api_secret']);
        $this->assertEquals('[REDACTED]', $sanitized['nested']['deep_nested']['password']);
    }

    public function test_admin_audit_log_api_controller_outcome_filtering_and_sorting(): void
    {
        // Clear previous logs
        AuditLog::query()->delete();

        // Create success log
        AuditLog::create([
            'actor_user_id' => $this->superAdmin->id,
            'actor_email'   => $this->superAdmin->email,
            'action'        => 'plan.create',
            'entity_type'   => 'Plan',
            'entity_id'     => 1,
            'after_state'   => ['outcome' => 'success'],
            'created_at'    => Carbon::now()->subMinutes(10),
        ]);

        // Create failure log
        AuditLog::create([
            'actor_user_id' => null,
            'actor_email'   => 'attacker@evil.com',
            'action'        => 'auth.failed',
            'entity_type'   => 'user',
            'entity_id'     => null,
            'after_state'   => ['outcome' => 'failure', 'attempted_email' => 'attacker@evil.com'],
            'created_at'    => Carbon::now()->subMinutes(5),
        ]);

        // Create denial log
        AuditLog::create([
            'actor_user_id' => $this->tenant->id,
            'actor_email'   => $this->tenant->email,
            'action'        => 'security.authorization_denied',
            'entity_type'   => 'audit_log',
            'entity_id'     => null,
            'after_state'   => ['outcome' => 'denial', 'required_permission' => 'admin.audit_logs.read'],
            'created_at'    => Carbon::now()->subMinutes(1),
        ]);

        // Filter: outcome=success
        $resSuccess = $this->actingAs($this->superAdmin)->getJson('/api/admin/audit-logs?outcome=success');
        $resSuccess->assertStatus(200);
        $this->assertCount(1, $resSuccess->json('data'));
        $this->assertEquals('plan.create', $resSuccess->json('data.0.action'));
        $this->assertEquals('success', $resSuccess->json('data.0.outcome'));

        // Filter: outcome=failure
        $resFailure = $this->actingAs($this->superAdmin)->getJson('/api/admin/audit-logs?outcome=failure');
        $resFailure->assertStatus(200);
        $this->assertCount(1, $resFailure->json('data'));
        $this->assertEquals('auth.failed', $resFailure->json('data.0.action'));
        $this->assertEquals('failure', $resFailure->json('data.0.outcome'));

        // Filter: outcome=denial
        $resDenial = $this->actingAs($this->superAdmin)->getJson('/api/admin/audit-logs?outcome=denial');
        $resDenial->assertStatus(200);
        $this->assertCount(1, $resDenial->json('data'));
        $this->assertEquals('security.authorization_denied', $resDenial->json('data.0.action'));
        $this->assertEquals('denial', $resDenial->json('data.0.outcome'));

        // Sorting: sort_by=created_at&direction=asc
        $resSort = $this->actingAs($this->superAdmin)->getJson('/api/admin/audit-logs?sort_by=created_at&direction=asc');
        $resSort->assertStatus(200);
        $data = $resSort->json('data');
        $this->assertCount(3, $data);
        $this->assertEquals('plan.create', $data[0]['action']);
        $this->assertEquals('security.authorization_denied', $data[2]['action']);
    }
}
