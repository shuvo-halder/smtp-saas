<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminUserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create([
            'name' => 'Starter Plan',
            'slug' => 'starter',
            'max_domains' => 2,
            'max_mailboxes_per_domain' => 5,
            'storage_mb_per_mailbox' => 1024,
            'max_aliases_per_domain' => 5,
            'daily_outbound_recipients' => 500,
            'mailbox_daily_outbound_recipients' => 100,
            'price_monthly' => 10,
            'price_yearly' => 100,
            'is_active' => true,
        ]);

        $this->admin = User::factory()->superAdmin()->create([
            'name' => 'Admin User',
            'email' => 'admin@mailsaas.com',
            'is_admin' => true,
            'status' => 'active',
        ]);

        $this->tenant = User::factory()->create([
            'name' => 'Tenant User',
            'email' => 'tenant@mailsaas.com',
            'is_admin' => false,
            'status' => 'active',
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);
    }

    /**
     * Test admin can suspend and reactivate a customer tenant via existing API.
     */
    public function test_admin_can_suspend_and_activate_tenant_and_domains(): void
    {
        $domain = Domain::create([
            'user_id' => $this->tenant->id,
            'domain_name' => 'tenant-corp.com',
            'status' => 'active',
            'mx_verified' => true,
        ]);

        // 1. Suspend tenant
        $response = $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->tenant->id}/suspend");
        $response->assertStatus(200);

        $this->tenant->refresh();
        $domain->refresh();

        $this->assertEquals('suspended', $this->tenant->status);
        $this->assertEquals('suspended', $domain->status);

        // 2. Reactivate tenant
        $reactivateResponse = $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->tenant->id}/activate");
        $reactivateResponse->assertStatus(200);

        $this->tenant->refresh();
        $domain->refresh();

        $this->assertEquals('active', $this->tenant->status);
        $this->assertEquals('active', $domain->status);
    }

    /**
     * Test suspended tenant is rejected by EnsureActiveSubscription middleware on subscription-required endpoints.
     */
    public function test_suspended_tenant_is_blocked_by_ensure_active_subscription(): void
    {
        // Active tenant can access subscription-required endpoints (domains)
        $activeResponse = $this->actingAs($this->tenant)->getJson('/api/domains');
        $activeResponse->assertStatus(200);

        // Suspend the tenant via admin API
        $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->tenant->id}/suspend");
        $this->tenant->refresh();

        // Suspended tenant now receives 403 Forbidden on subscription-required routes
        $blockedResponse = $this->actingAs($this->tenant)->getJson('/api/domains');
        $blockedResponse->assertStatus(403)
            ->assertJson(['message' => 'Active subscription required.']);
    }

    /**
     * Test existing suspendUser endpoint updates database status but does not automatically revoke database session records.
     */
    public function test_account_suspension_does_not_revoke_existing_database_session(): void
    {
        // Simulate an existing session in database sessions table
        DB::table('sessions')->insert([
            'id' => 'test-session-id-12345',
            'user_id' => $this->tenant->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit Test',
            'payload' => serialize(['test' => true]),
            'last_activity' => time(),
        ]);

        $this->assertDatabaseHas('sessions', [
            'id' => 'test-session-id-12345',
            'user_id' => $this->tenant->id,
        ]);

        // Suspend the user via Admin API
        $response = $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->tenant->id}/suspend");
        $response->assertStatus(200);

        // Session record remains in sessions table (demonstrating absence of third-party session revocation)
        $this->assertDatabaseHas('sessions', [
            'id' => 'test-session-id-12345',
            'user_id' => $this->tenant->id,
        ]);

        // User can still hit /api/auth/user with their authenticated session
        $this->tenant->refresh();
        $authResponse = $this->actingAs($this->tenant)->getJson('/api/auth/user');
        $authResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'suspended');
    }

    /**
     * Test active administrators retain authorized access to admin endpoints.
     */
    public function test_active_administrator_retains_authorized_admin_access(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/stats');
        $response->assertStatus(200);
    }

    /**
     * Test that an administrator with status = 'suspended' is strictly denied access by EnsureAdmin.
     */
    public function test_suspended_administrator_is_denied_admin_access(): void
    {
        $suspendedAdmin = User::factory()->superAdmin()->create([
            'name' => 'Suspended Admin',
            'email' => 'suspended-admin@mailsaas.com',
            'is_admin' => true,
            'status' => 'suspended',
        ]);

        $response = $this->actingAs($suspendedAdmin)->getJson('/api/admin/stats');
        $response->assertStatus(403)
            ->assertJson(['message' => 'Admin access required.']);
    }

    /**
     * Test that ordinary non-admin users remain denied admin access.
     */
    public function test_ordinary_non_admin_user_is_denied_admin_access(): void
    {
        $response = $this->actingAs($this->tenant)->getJson('/api/admin/stats');
        $response->assertStatus(403)
            ->assertJson(['message' => 'Admin access required.']);
    }

    /**
     * Test that unauthenticated requests retain existing authentication behavior (401 Unauthorized).
     */
    public function test_unauthenticated_request_is_denied_with_unauthenticated_status(): void
    {
        $response = $this->getJson('/api/admin/stats');
        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
