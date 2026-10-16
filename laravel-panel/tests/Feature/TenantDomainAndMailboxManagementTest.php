<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\DnsVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TenantDomainAndMailboxManagementTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private User $tenant;
    private User $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                              => 'Business Pro',
            'slug'                              => 'business-pro',
            'description'                       => 'Standard plan',
            'max_domains'                       => 2,
            'max_mailboxes_per_domain'          => 3,
            'storage_mb_per_mailbox'            => 2048,
            'max_aliases_per_domain'            => 5,
            'daily_outbound_recipients'         => 1000,
            'mailbox_daily_outbound_recipients' => 200,
            'price_monthly'                     => 2000.00,
            'price_yearly'                      => 20000.00,
            'features'                          => ['Custom Domain', 'Webmail'],
            'is_active'                         => true,
            'is_featured'                       => true,
            'sort_order'                        => 1,
        ]);

        $this->tenant = User::create([
            'name'            => 'Tenant One',
            'email'           => 'tenant1@example.com',
            'password'        => bcrypt('Password123!'),
            'company_name'    => 'Tenant Corp',
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->addDays(30),
            'is_admin'        => false,
        ]);

        $this->otherTenant = User::create([
            'name'            => 'Tenant Two',
            'email'           => 'tenant2@example.com',
            'password'        => bcrypt('Password123!'),
            'company_name'    => 'Other Corp',
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->addDays(30),
            'is_admin'        => false,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_domains_or_mailboxes(): void
    {
        $this->getJson('/api/domains')->assertStatus(401);
        $this->getJson('/api/mailboxes')->assertStatus(401);
        $this->postJson('/api/domains', ['domain_name' => 'test.com'])->assertStatus(401);
    }

    public function test_tenant_can_list_domains_with_pagination_and_search(): void
    {
        $d1 = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'alpha.com',
            'status'      => 'active',
        ]);
        $d2 = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'beta.com',
            'status'      => 'pending',
        ]);

        $response = $this->actingAs($this->tenant)
            ->getJson('/api/domains?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.domain_name', 'beta.com')
            ->assertJsonPath('data.1.domain_name', 'alpha.com');

        // Test search filter
        $searchRes = $this->actingAs($this->tenant)
            ->getJson('/api/domains?search=alpha');

        $searchRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.domain_name', 'alpha.com');

        // Test status filter
        $statusRes = $this->actingAs($this->tenant)
            ->getJson('/api/domains?status=pending');

        $statusRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.domain_name', 'beta.com');
    }

    public function test_tenant_can_add_domain_with_case_normalization(): void
    {
        $response = $this->actingAs($this->tenant)
            ->postJson('/api/domains', [
                'domain_name' => '  MY-COMPANY.COM  ',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.domain_name', 'my-company.com')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('domains', [
            'user_id'     => $this->tenant->id,
            'domain_name' => 'my-company.com',
            'status'      => 'pending',
        ]);
    }

    public function test_tenant_cannot_exceed_plan_domain_limit(): void
    {
        // Plan limit is 2
        Domain::create(['user_id' => $this->tenant->id, 'domain_name' => 'one.com', 'status' => 'active']);
        Domain::create(['user_id' => $this->tenant->id, 'domain_name' => 'two.com', 'status' => 'active']);

        $response = $this->actingAs($this->tenant)
            ->postJson('/api/domains', [
                'domain_name' => 'three.com',
            ]);

        $response->assertStatus(403);
    }

    public function test_tenant_can_view_own_domain_detail(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'acme.org',
            'status'      => 'active',
            'mx_verified' => true,
        ]);

        $response = $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$domain->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.domain_name', 'acme.org')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_cross_tenant_cannot_view_or_manage_another_tenants_domain(): void
    {
        $otherDomain = Domain::create([
            'user_id'     => $this->otherTenant->id,
            'domain_name' => 'other.com',
            'status'      => 'active',
        ]);

        // Attempt view
        $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$otherDomain->id}")
            ->assertStatus(403);

        // Attempt verify
        $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$otherDomain->id}/verify")
            ->assertStatus(403);

        // Attempt delete
        $this->actingAs($this->tenant)
            ->deleteJson("/api/domains/{$otherDomain->id}")
            ->assertStatus(403);

        // Attempt add mailbox on other tenant domain
        $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$otherDomain->id}/mailboxes", [
                'local_part' => 'hacker',
                'password'   => 'Secret12345!',
            ])
            ->assertStatus(403);
    }

    public function test_domain_dns_verification_reports_truthful_state(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'verify-me.com',
            'status'      => 'pending',
        ]);

        $mockDns = Mockery::mock(DnsVerificationService::class);
        $mockDns->shouldReceive('verifyAll')
            ->once()
            ->with(Mockery::on(fn($d) => $d->id === $domain->id))
            ->andReturn([
                'mx'    => ['verified' => false, 'found' => null, 'expected' => 'mail.example.com'],
                'spf'   => ['verified' => false, 'found' => null, 'expected' => 'v=spf1 ...'],
                'dkim'  => ['verified' => false, 'found' => null, 'expected' => 'v=DKIM1 ...'],
                'dmarc' => ['verified' => false, 'found' => null, 'expected' => 'v=DMARC1 ...'],
            ]);
        $this->app->instance(DnsVerificationService::class, $mockDns);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/verify");

        $response->assertStatus(200)
            ->assertJsonPath('verified', false)
            ->assertJsonPath('domain.status', 'pending');
    }

    public function test_tenant_can_delete_domain_and_associated_mailboxes_are_deleted(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'to-delete.com',
            'status'      => 'active',
        ]);

        $mailbox = Mailbox::create([
            'domain_id'   => $domain->id,
            'local_part'  => 'info',
            'email'       => 'info@to-delete.com',
            'password'    => crypt('Pass12345!', '$6$salt$'),
            'quota_mb'    => 1024,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->tenant)
            ->deleteJson("/api/domains/{$domain->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('domains', ['id' => $domain->id]);
        $this->assertSoftDeleted('mailboxes', ['id' => $mailbox->id]);
    }

    public function test_tenant_can_list_all_mailboxes_across_domains(): void
    {
        $d1 = Domain::create(['user_id' => $this->tenant->id, 'domain_name' => 'dom1.com', 'status' => 'active']);
        $d2 = Domain::create(['user_id' => $this->tenant->id, 'domain_name' => 'dom2.com', 'status' => 'active']);

        $m1 = Mailbox::create([
            'domain_id'   => $d1->id,
            'local_part'  => 'user1',
            'email'       => 'user1@dom1.com',
            'password'    => crypt('Pass12345!', '$6$salt$'),
            'is_active'   => true,
        ]);
        $m2 = Mailbox::create([
            'domain_id'   => $d2->id,
            'local_part'  => 'user2',
            'email'       => 'user2@dom2.com',
            'password'    => crypt('Pass12345!', '$6$salt$'),
            'is_active'   => false,
        ]);

        // List all
        $response = $this->actingAs($this->tenant)
            ->getJson('/api/mailboxes');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Filter by domain_id
        $filterDomainRes = $this->actingAs($this->tenant)
            ->getJson("/api/mailboxes?domain_id={$d1->id}");

        $filterDomainRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'user1@dom1.com');

        // Filter by status
        $filterStatusRes = $this->actingAs($this->tenant)
            ->getJson('/api/mailboxes?status=disabled');

        $filterStatusRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'user2@dom2.com');
    }

    public function test_tenant_can_create_mailbox_on_active_domain(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'active-domain.com',
            'status'      => 'active',
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/mailboxes", [
                'local_part'   => 'sales',
                'password'     => 'ValidPass123!',
                'display_name' => 'Sales Team',
                'quota_mb'     => 1024,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'sales@active-domain.com')
            ->assertJsonPath('data.display_name', 'Sales Team')
            ->assertJsonPath('data.domain_name', 'active-domain.com')
            ->assertJsonPath('data.quota_mb', 1024)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissing(['password']);

        $this->assertDatabaseHas('mailboxes', [
            'domain_id'  => $domain->id,
            'local_part' => 'sales',
            'email'      => 'sales@active-domain.com',
            'is_active'  => 1,
        ]);
    }

    public function test_tenant_cannot_create_mailbox_on_unverified_or_pending_domain(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'unverified.com',
            'status'      => 'pending',
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/mailboxes", [
                'local_part' => 'sales',
                'password'   => 'ValidPass123!',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot create mailbox on an unverified or inactive domain. Please verify domain DNS records first.'
            ]);
    }

    public function test_tenant_cannot_create_mailbox_exceeding_plan_storage_cap(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'storage-test.com',
            'status'      => 'active',
        ]);

        // Plan storage cap is 2048 MB
        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/mailboxes", [
                'local_part' => 'bigstorage',
                'password'   => 'ValidPass123!',
                'quota_mb'   => 5000,
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Mailbox storage quota cannot exceed plan limit of 2048 MB.'
            ]);
    }

    public function test_tenant_cannot_exceed_plan_mailboxes_per_domain_limit(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'quota-test.com',
            'status'      => 'active',
        ]);

        // Max mailboxes per domain is 3
        for ($i = 1; $i <= 3; $i++) {
            Mailbox::create([
                'domain_id'   => $domain->id,
                'local_part'  => "user{$i}",
                'email'       => "user{$i}@quota-test.com",
                'password'    => crypt('Pass123!', '$6$salt$'),
                'is_active'   => true,
            ]);
        }

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/mailboxes", [
                'local_part' => 'overflow',
                'password'   => 'ValidPass123!',
            ]);

        $response->assertStatus(403);
    }

    public function test_mailbox_password_is_hashed_with_sha512_crypt_and_never_exposed_in_api(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'crypto-test.com',
            'status'      => 'active',
        ]);

        $rawPassword = 'SuperSecretPassword99!';

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/mailboxes", [
                'local_part' => 'cryptouser',
                'password'   => $rawPassword,
            ]);

        $response->assertStatus(201)
            ->assertJsonMissing(['password']);

        $mailbox = Mailbox::where('email', 'cryptouser@crypto-test.com')->first();
        $this->assertNotNull($mailbox);
        $this->assertStringStartsWith('$6$', $mailbox->password, 'Dovecot password must be SHA512-CRYPT');

        // Test show endpoint also doesn't expose password
        $showRes = $this->actingAs($this->tenant)
            ->getJson("/api/mailboxes/{$mailbox->id}");

        $showRes->assertStatus(200)
            ->assertJsonMissing(['password']);
    }

    public function test_tenant_can_update_mailbox_display_name_only(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'update-test.com',
            'status'      => 'active',
        ]);

        $mailbox = Mailbox::create([
            'domain_id'    => $domain->id,
            'local_part'   => 'support',
            'email'        => 'support@update-test.com',
            'password'     => crypt('Pass123!', '$6$salt$'),
            'display_name' => 'Old Name',
            'quota_mb'     => 1024,
            'is_active'    => true,
        ]);

        $response = $this->actingAs($this->tenant)
            ->putJson("/api/mailboxes/{$mailbox->id}", [
                'display_name' => 'Customer Care Team',
                'quota_mb'     => 99999, // Should be ignored
                'email'        => 'hacked@update-test.com', // Should be ignored
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.display_name', 'Customer Care Team')
            ->assertJsonPath('data.email', 'support@update-test.com')
            ->assertJsonPath('data.quota_mb', 1024);

        $mailbox->refresh();
        $this->assertEquals('Customer Care Team', $mailbox->display_name);
        $this->assertEquals('support@update-test.com', $mailbox->email);
        $this->assertEquals(1024, $mailbox->quota_mb);
    }

    public function test_tenant_can_change_mailbox_password(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'pw-test.com',
            'status'      => 'active',
        ]);

        $mailbox = Mailbox::create([
            'domain_id'   => $domain->id,
            'local_part'  => 'staff',
            'email'       => 'staff@pw-test.com',
            'password'    => crypt('OldPassword123!', '$6$oldsalt$'),
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/mailboxes/{$mailbox->id}/change-password", [
                'password' => 'NewSecurePassword456!',
            ]);

        $response->assertStatus(200);

        $mailbox->refresh();
        $this->assertStringStartsWith('$6$', $mailbox->password);
        $this->assertTrue(hash_equals($mailbox->password, crypt('NewSecurePassword456!', $mailbox->password)));
    }

    public function test_tenant_can_toggle_mailbox_active_status(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'toggle-test.com',
            'status'      => 'active',
        ]);

        $mailbox = Mailbox::create([
            'domain_id'   => $domain->id,
            'local_part'  => 'toggleme',
            'email'       => 'toggleme@toggle-test.com',
            'password'    => crypt('Pass123!', '$6$salt$'),
            'is_active'   => true,
        ]);

        // Toggle to disabled
        $res1 = $this->actingAs($this->tenant)
            ->postJson("/api/mailboxes/{$mailbox->id}/toggle");

        $res1->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($mailbox->fresh()->is_active);

        // Toggle back to active
        $res2 = $this->actingAs($this->tenant)
            ->postJson("/api/mailboxes/{$mailbox->id}/toggle");

        $res2->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($mailbox->fresh()->is_active);
    }

    public function test_tenant_cannot_enable_mailbox_if_domain_is_inactive(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'inactive-dom.com',
            'status'      => 'pending',
        ]);

        $mailbox = Mailbox::create([
            'domain_id'   => $domain->id,
            'local_part'  => 'off',
            'email'       => 'off@inactive-dom.com',
            'password'    => crypt('Pass123!', '$6$salt$'),
            'is_active'   => false,
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/mailboxes/{$mailbox->id}/toggle");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot enable mailbox on an unverified or inactive domain.'
            ]);

        $this->assertFalse($mailbox->fresh()->is_active);
    }

    public function test_tenant_cannot_enable_mailbox_if_subscription_is_expired_or_suspended(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'sub-test.com',
            'status'      => 'active',
        ]);

        $mailbox = Mailbox::create([
            'domain_id'   => $domain->id,
            'local_part'  => 'quarantined',
            'email'       => 'quarantined@sub-test.com',
            'password'    => crypt('Pass123!', '$6$salt$'),
            'is_active'   => false,
        ]);

        // Expire subscription
        $this->tenant->update([
            'plan_expires_at' => now()->subDay(),
        ]);

        // EnsureActiveSubscription middleware returns 403 on tenant actions when expired
        $response = $this->actingAs($this->tenant)
            ->postJson("/api/mailboxes/{$mailbox->id}/toggle");

        $response->assertStatus(403);
    }

    public function test_cross_tenant_cannot_view_or_manage_another_tenants_mailbox(): void
    {
        $otherDomain = Domain::create([
            'user_id'     => $this->otherTenant->id,
            'domain_name' => 'private-other.com',
            'status'      => 'active',
        ]);

        $otherMailbox = Mailbox::create([
            'domain_id'   => $otherDomain->id,
            'local_part'  => 'ceo',
            'email'       => 'ceo@private-other.com',
            'password'    => crypt('Pass123!', '$6$salt$'),
            'is_active'   => true,
        ]);

        // View
        $this->actingAs($this->tenant)
            ->getJson("/api/mailboxes/{$otherMailbox->id}")
            ->assertStatus(403);

        // Update
        $this->actingAs($this->tenant)
            ->putJson("/api/mailboxes/{$otherMailbox->id}", ['display_name' => 'Hacked'])
            ->assertStatus(403);

        // Change password
        $this->actingAs($this->tenant)
            ->postJson("/api/mailboxes/{$otherMailbox->id}/change-password", ['password' => 'HackedPass123!'])
            ->assertStatus(403);

        // Toggle
        $this->actingAs($this->tenant)
            ->postJson("/api/mailboxes/{$otherMailbox->id}/toggle")
            ->assertStatus(403);

        // Delete
        $this->actingAs($this->tenant)
            ->deleteJson("/api/mailboxes/{$otherMailbox->id}")
            ->assertStatus(403);
    }

    public function test_suspended_tenant_is_blocked_from_domain_and_mailbox_management(): void
    {
        $this->tenant->update(['status' => 'suspended']);

        $this->actingAs($this->tenant)
            ->getJson('/api/domains')
            ->assertStatus(403);

        $this->actingAs($this->tenant)
            ->getJson('/api/mailboxes')
            ->assertStatus(403);
    }
}
