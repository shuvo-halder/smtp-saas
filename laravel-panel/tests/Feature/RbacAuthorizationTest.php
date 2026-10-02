<?php

namespace Tests\Feature;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Exceptions\SuperAdminGovernanceException;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\SecurityAuditLogger;
use App\Services\SuperAdminGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private Domain $domain;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox' => 1024,
            'max_aliases_per_domain' => 5,
            'daily_outbound_recipients' => 500,
            'mailbox_daily_outbound_recipients' => 100,
            'price_monthly' => 20,
            'price_yearly' => 200,
            'is_active' => true,
        ]);

        $tenant = User::factory()->create([
            'name' => 'Sample Tenant',
            'email' => 'sample-tenant@client.com',
            'is_admin' => false,
            'status' => 'active',
            'plan_id' => $this->plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        $this->domain = Domain::create([
            'user_id' => $tenant->id,
            'domain_name' => 'client-corp.com',
            'status' => 'active',
            'mx_verified' => true,
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id' => $this->domain->id,
            'local_part' => 'info',
            'email' => 'info@client-corp.com',
            'password' => crypt('InitialPass123!', '$6$rounds=5000$saltsalt$'),
            'quota_mb' => 1024,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // 1. Authentication & Boundary Tests
    // =========================================================================

    public function test_unauthenticated_request_is_denied_with_401(): void
    {
        $response = $this->getJson('/api/admin/stats');
        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_authenticated_ordinary_tenant_is_denied_with_403(): void
    {
        $tenant = User::factory()->create(['is_admin' => false]);
        $response = $this->actingAs($tenant)->getJson('/api/admin/stats');
        $response->assertStatus(403)
            ->assertJson(['message' => 'Admin access required.']);
    }

    public function test_suspended_administrator_is_denied_with_403(): void
    {
        $suspendedAdmin = User::factory()->superAdmin()->create(['status' => 'suspended']);
        $response = $this->actingAs($suspendedAdmin)->getJson('/api/admin/stats');
        $response->assertStatus(403)
            ->assertJson(['message' => 'Admin access required.']);
    }

    public function test_zero_role_administrator_is_denied_access_to_permission_routes_per_rbac_dec_10(): void
    {
        // Newly created administrator with is_admin = true but zero roles assigned per RBAC-DEC-10
        $zeroRoleAdmin = User::factory()->withoutRoles()->create();
        $this->assertTrue($zeroRoleAdmin->is_admin);
        $this->assertCount(0, $zeroRoleAdmin->roles);

        $response = $this->actingAs($zeroRoleAdmin)->getJson('/api/admin/stats');
        $response->assertStatus(403)
            ->assertJson(['message' => 'Forbidden: missing required permission [admin.stats.read].']);
    }

    // =========================================================================
    // 2. Role Permissions & Segregation (RBAC-DEC-02, RBAC-DEC-03)
    // =========================================================================

    public function test_super_admin_has_explicit_permissions_to_all_admin_endpoints(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        // 1. Stats
        $this->actingAs($superAdmin)->getJson('/api/admin/stats')->assertStatus(200);

        // 2. Users
        $this->actingAs($superAdmin)->getJson('/api/admin/users')->assertStatus(200);

        // 3. Domains
        $this->actingAs($superAdmin)->getJson('/api/admin/domains')->assertStatus(200);

        // 4. Mailboxes
        $this->actingAs($superAdmin)->getJson('/api/admin/mailboxes')->assertStatus(200);

        // 5. Plans
        $this->actingAs($superAdmin)->getJson('/api/admin/plans')->assertStatus(200);

        // 6. Invoices
        $this->actingAs($superAdmin)->getJson('/api/admin/invoices')->assertStatus(200);

        // 7. SMTP Overview
        $this->actingAs($superAdmin)->getJson('/api/admin/smtp/overview')->assertStatus(200);

        // 8. Audit Logs
        $this->actingAs($superAdmin)->getJson('/api/admin/audit-logs')->assertStatus(200);
    }

    public function test_deliverability_operator_has_smtp_access_but_denied_plans_management(): void
    {
        $operator = User::factory()->deliverabilityOperator()->create();

        // Allowed: SMTP overview
        $this->actingAs($operator)->getJson('/api/admin/smtp/overview')->assertStatus(200);

        // Allowed: Mailbox toggle
        $toggleResponse = $this->actingAs($operator)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle", [
            'reason' => 'Routine test',
        ]);
        $toggleResponse->assertStatus(200);

        // Denied: Plans management (missing admin.plans.manage)
        $planResponse = $this->actingAs($operator)->postJson('/api/admin/plans', [
            'name' => 'Hacker Plan',
            'slug' => 'hacker-plan',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 10,
            'max_aliases_per_domain' => 1,
            'daily_outbound_recipients' => 10,
            'mailbox_daily_outbound_recipients' => 10,
            'price_monthly' => 10,
            'price_yearly' => 100,
        ]);
        $planResponse->assertStatus(403)
            ->assertJson(['message' => 'Forbidden: missing required permission [admin.plans.manage].']);
    }

    public function test_customer_support_has_read_access_but_denied_mutations(): void
    {
        $support = User::factory()->customerSupport()->create();

        // Allowed: Read plans and users
        $this->actingAs($support)->getJson('/api/admin/plans')->assertStatus(200);
        $this->actingAs($support)->getJson('/api/admin/users')->assertStatus(200);

        // Denied: Mailbox toggle (missing admin.smtp.mailbox.toggle)
        $this->actingAs($support)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/toggle")
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden: missing required permission [admin.smtp.mailbox.toggle].']);
    }

    // =========================================================================
    // 3. Mailbox Password Reset Authorization (RBAC-DEC-03)
    // =========================================================================

    public function test_mailbox_password_reset_allowed_for_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-password", [
                'reason' => 'Tenant requested password recovery',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['mailbox_id', 'email', 'new_password']);
        $this->assertNotEmpty($response->json('new_password'));
    }

    public function test_mailbox_password_reset_allowed_for_deliverability_operator_per_rbac_dec_03(): void
    {
        $operator = User::factory()->deliverabilityOperator()->create();

        $response = $this->actingAs($operator)
            ->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-password", [
                'reason' => 'Frontline deliverability operator reset',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['mailbox_id', 'email', 'new_password']);
        $this->assertNotEmpty($response->json('new_password'));
    }

    public function test_mailbox_password_reset_denied_for_customer_support(): void
    {
        $support = User::factory()->customerSupport()->create();

        $response = $this->actingAs($support)
            ->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-password", [
                'reason' => 'Unauthorized reset attempt',
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Forbidden: missing required permission [admin.smtp.mailbox.reset_password].']);
    }

    // =========================================================================
    // 4. Tenant Administrator Segregation (RBAC-DEC-04)
    // =========================================================================

    public function test_admin_can_suspend_ordinary_tenant_user(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ordinaryTenant = User::factory()->create(['is_admin' => false, 'status' => 'active']);

        $response = $this->actingAs($superAdmin)
            ->postJson("/api/admin/users/{$ordinaryTenant->id}/suspend");

        $response->assertStatus(200);
        $ordinaryTenant->refresh();
        $this->assertEquals('suspended', $ordinaryTenant->status);
    }

    public function test_tenant_endpoint_strictly_rejects_suspending_administrator_per_rbac_dec_04(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $targetAdmin = User::factory()->deliverabilityOperator()->create();

        $response = $this->actingAs($superAdmin)
            ->postJson("/api/admin/users/{$targetAdmin->id}/suspend");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Cannot modify administrator status via tenant endpoints.']);

        $targetAdmin->refresh();
        $this->assertEquals('active', $targetAdmin->status);
    }

    public function test_tenant_endpoint_strictly_rejects_activating_administrator_per_rbac_dec_04(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $targetAdmin = User::factory()->deliverabilityOperator()->create(['status' => 'suspended']);

        $response = $this->actingAs($superAdmin)
            ->postJson("/api/admin/users/{$targetAdmin->id}/activate");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Cannot modify administrator status via tenant endpoints.']);

        $targetAdmin->refresh();
        $this->assertEquals('suspended', $targetAdmin->status);
    }

    // =========================================================================
    // 5. Super Admin Governance & Concurrency Locks (RBAC-DEC-06)
    // =========================================================================

    public function test_final_super_admin_cannot_be_demoted(): void
    {
        $governanceService = app(SuperAdminGovernanceService::class);

        // Ensure exactly 1 Super Admin exists
        $onlySuperAdmin = User::factory()->superAdmin()->create();
        $this->assertEquals(1, $governanceService->getActiveSuperAdminCount());

        $this->expectException(SuperAdminGovernanceException::class);
        $this->expectExceptionMessage('Cannot de-escalate or deactivate the final active Super Admin.');

        $governanceService->demoteSuperAdmin($onlySuperAdmin);
    }

    public function test_super_admin_demotion_succeeds_when_other_super_admins_remain(): void
    {
        $governanceService = app(SuperAdminGovernanceService::class);

        $superAdmin1 = User::factory()->superAdmin()->create(['email' => 'admin1@platform.com']);
        $superAdmin2 = User::factory()->superAdmin()->create(['email' => 'admin2@platform.com']);
        $this->assertEquals(2, $governanceService->getActiveSuperAdminCount());

        $governanceService->demoteSuperAdmin($superAdmin1);

        $superAdmin1->refresh();
        $this->assertFalse($superAdmin1->hasRole(RoleEnum::SUPER_ADMIN->value));
        $this->assertEquals(1, $governanceService->getActiveSuperAdminCount());
    }

    public function test_governance_service_uses_dedicated_governance_locks_table(): void
    {
        $governanceService = app(SuperAdminGovernanceService::class);

        $executed = false;
        $governanceService->executeWithLock(function () use (&$executed) {
            $lockRow = DB::table('governance_locks')
                ->where('lock_name', SuperAdminGovernanceService::LOCK_NAME)
                ->first();

            $this->assertNotNull($lockRow);
            $this->assertNotNull($lockRow->owner);
            $this->assertNotNull($lockRow->acquired_at);
            $executed = true;
        });

        $this->assertTrue($executed);

        // Lock metadata must be cleared after release
        $lockRowAfter = DB::table('governance_locks')
            ->where('lock_name', SuperAdminGovernanceService::LOCK_NAME)
            ->first();
        $this->assertNull($lockRowAfter->owner);
        $this->assertNull($lockRowAfter->acquired_at);
    }

    // =========================================================================
    // 6. Emergency Recovery Command (RBAC-DEC-07)
    // =========================================================================

    public function test_emergency_recovery_preserves_suspended_status_without_reactivate_flag(): void
    {
        $suspendedAdmin = User::factory()->superAdmin()->create(['status' => 'suspended']);

        $exitCode = Artisan::call('rbac:emergency-recovery', [
            'email' => $suspendedAdmin->email,
        ]);

        $this->assertEquals(1, $exitCode);
        $suspendedAdmin->refresh();
        $this->assertEquals('suspended', $suspendedAdmin->status);
    }

    public function test_emergency_recovery_reactivates_account_with_explicit_reactivate_flag(): void
    {
        $suspendedAdmin = User::factory()->withoutRoles()->create(['status' => 'suspended']);

        $exitCode = Artisan::call('rbac:emergency-recovery', [
            'email'        => $suspendedAdmin->email,
            '--reactivate' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $suspendedAdmin->refresh();
        $this->assertEquals('active', $suspendedAdmin->status);
        $this->assertTrue($suspendedAdmin->hasRole(RoleEnum::SUPER_ADMIN->value));
    }

    // =========================================================================
    // 7. Authorization Denial Logging & Sanitization (RBAC-DEC-05)
    // =========================================================================

    public function test_authorization_denial_is_logged_to_security_channel_with_redaction(): void
    {
        Log::shouldReceive('channel')
            ->with('security')
            ->atLeast()->once()
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(function ($message, $context) {
                // Ensure sensitive payload keys are redacted
                if (isset($context['context']['password'])) {
                    return $context['context']['password'] === '[REDACTED]';
                }
                return true;
            });

        // Trigger an unauthorized request
        $support = User::factory()->customerSupport()->create();
        $this->actingAs($support)->postJson("/api/admin/smtp/mailboxes/{$this->mailbox->id}/reset-password", [
            'password' => 'SuperSecret123!',
        ]);
    }

    // =========================================================================
    // 8. Migration Idempotency & Provenance (RBAC-DEC-09, Supp. 2)
    // =========================================================================

    public function test_backfill_migration_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_10_03_000003_seed_rbac_and_backfill_legacy_admins.php');

        // Re-executing up() must not fail with unique constraint violations or duplicate records
        $migration->up();

        $superAdminRole = DB::table('roles')->where('name', RoleEnum::SUPER_ADMIN->value)->first();
        $this->assertNotNull($superAdminRole);

        $permissionCount = DB::table('permissions')->count();
        $this->assertEquals(count(PermissionEnum::cases()), $permissionCount);
    }
}
