<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\EmailAlias;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAliasManagementTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private User $tenant;
    private User $otherTenant;
    private Domain $activeDomain;
    private Domain $pendingDomain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                              => 'Business Pro',
            'slug'                              => 'business-pro',
            'description'                       => 'Standard business plan',
            'max_domains'                       => 3,
            'max_mailboxes_per_domain'          => 5,
            'storage_mb_per_mailbox'            => 2048,
            'max_aliases_per_domain'            => 3,
            'daily_outbound_recipients'         => 1000,
            'mailbox_daily_outbound_recipients' => 200,
            'price_monthly'                     => 2000.00,
            'price_yearly'                      => 20000.00,
            'features'                          => ['Custom Domain', 'Webmail', 'Aliases'],
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

        $this->activeDomain = Domain::create([
            'user_id'       => $this->tenant->id,
            'domain_name'   => 'alpha.com',
            'status'        => 'active',
            'mx_verified'   => true,
            'spf_verified'  => true,
            'dkim_verified' => true,
        ]);

        $this->pendingDomain = Domain::create([
            'user_id'       => $this->tenant->id,
            'domain_name'   => 'pending.com',
            'status'        => 'pending',
            'mx_verified'   => false,
            'spf_verified'  => false,
            'dkim_verified' => false,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_aliases(): void
    {
        $this->getJson('/api/aliases')->assertStatus(401);
        $this->postJson('/api/aliases', [])->assertStatus(401);
        $this->getJson('/api/aliases/1')->assertStatus(401);
        $this->putJson('/api/aliases/1', [])->assertStatus(401);
        $this->postJson('/api/aliases/1/toggle')->assertStatus(401);
        $this->deleteJson('/api/aliases/1')->assertStatus(401);
        $this->getJson('/api/domains/' . $this->activeDomain->id . '/aliases')->assertStatus(401);
    }

    public function test_suspended_tenant_is_blocked_from_aliases(): void
    {
        $this->tenant->update(['status' => 'suspended']);

        $this->actingAs($this->tenant)
            ->getJson('/api/aliases')
            ->assertStatus(403);
    }

    public function test_tenant_can_list_aliases_across_domains_with_search_and_filters(): void
    {
        $domain2 = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'beta.com',
            'status'      => 'active',
        ]);

        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'sales@alpha.com',
            'destination_email' => 'sales-team@external.com',
            'is_active'         => true,
        ]);

        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'support@alpha.com',
            'destination_email' => 'support-team@external.com',
            'is_active'         => false,
        ]);

        EmailAlias::create([
            'domain_id'         => $domain2->id,
            'source_email'      => 'contact@beta.com',
            'destination_email' => 'contact-team@external.com',
            'is_active'         => true,
        ]);

        // List all tenant aliases
        $response = $this->actingAs($this->tenant)->getJson('/api/aliases');
        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');

        // Filter by domain_id
        $domainResponse = $this->actingAs($this->tenant)
            ->getJson('/api/aliases?domain_id=' . $domain2->id);
        $domainResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_email', 'contact@beta.com');

        // Filter by status=active
        $activeResponse = $this->actingAs($this->tenant)
            ->getJson('/api/aliases?status=active');
        $activeResponse->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Filter by status=inactive
        $inactiveResponse = $this->actingAs($this->tenant)
            ->getJson('/api/aliases?status=inactive');
        $inactiveResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_email', 'support@alpha.com');

        // Search by query
        $searchResponse = $this->actingAs($this->tenant)
            ->getJson('/api/aliases?search=support');
        $searchResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_email', 'support@alpha.com');
    }

    public function test_tenant_can_list_domain_scoped_aliases(): void
    {
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'info@alpha.com',
            'destination_email' => 'info-dest@external.com',
            'is_active'         => true,
        ]);

        $response = $this->actingAs($this->tenant)
            ->getJson('/api/domains/' . $this->activeDomain->id . '/aliases');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_email', 'info@alpha.com')
            ->assertJsonPath('data.0.forwarding_status', 'synchronized');
    }

    public function test_tenant_can_create_alias_with_local_part(): void
    {
        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $this->activeDomain->id,
            'local_part'        => 'billing',
            'destination_email' => 'accounting@myfinance.com',
            'is_active'         => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.source_email', 'billing@alpha.com')
            ->assertJsonPath('data.destination_email', 'accounting@myfinance.com')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.forwarding_status', 'synchronized');

        $this->assertDatabaseHas('email_aliases', [
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'billing@alpha.com',
            'destination_email' => 'accounting@myfinance.com',
            'is_active'         => true,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'alias.create',
            'entity_type'   => 'EmailAlias',
            'actor_user_id' => $this->tenant->id,
        ]);
    }

    public function test_tenant_can_create_alias_with_full_source_email(): void
    {
        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'helpdesk@alpha.com',
            'destination_email' => 'remote-team@external.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.source_email', 'helpdesk@alpha.com');

        $this->assertDatabaseHas('email_aliases', [
            'source_email' => 'helpdesk@alpha.com',
        ]);
    }

    public function test_cannot_create_alias_with_mismatched_source_domain(): void
    {
        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'helpdesk@otherdomain.com',
            'destination_email' => 'remote-team@external.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Source email must belong to domain @alpha.com.');
    }

    public function test_cannot_create_alias_on_inactive_or_unverified_domain(): void
    {
        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $this->pendingDomain->id,
            'local_part'        => 'support',
            'destination_email' => 'dest@external.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot create alias on an unverified or inactive domain. Please verify domain DNS records first.');
    }

    public function test_cannot_create_alias_on_another_tenants_domain(): void
    {
        $otherDomain = Domain::create([
            'user_id'     => $this->otherTenant->id,
            'domain_name' => 'other.com',
            'status'      => 'active',
        ]);

        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $otherDomain->id,
            'local_part'        => 'sales',
            'destination_email' => 'dest@external.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The selected domain does not exist or does not belong to your account.');
    }

    public function test_cannot_create_alias_exceeding_plan_limit(): void
    {
        // Plan limit is 3 aliases per domain
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'alias1@alpha.com',
            'destination_email' => 'dest1@external.com',
            'is_active'         => true,
        ]);
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'alias2@alpha.com',
            'destination_email' => 'dest2@external.com',
            'is_active'         => true,
        ]);
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'alias3@alpha.com',
            'destination_email' => 'dest3@external.com',
            'is_active'         => true,
        ]);

        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $this->activeDomain->id,
            'local_part'        => 'alias4',
            'destination_email' => 'dest4@external.com',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Alias limit reached for this domain under your current plan.');
    }

    public function test_cannot_create_alias_colliding_with_existing_mailbox(): void
    {
        Mailbox::create([
            'domain_id'    => $this->activeDomain->id,
            'local_part'   => 'ceo',
            'email'        => 'ceo@alpha.com',
            'password'     => bcrypt('secret'),
            'quota_mb'     => 1024,
            'is_active'    => true,
        ]);

        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $this->activeDomain->id,
            'local_part'        => 'ceo',
            'destination_email' => 'dest@external.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'An active mailbox already exists with this email address.');
    }

    public function test_cannot_create_duplicate_alias_on_same_domain(): void
    {
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'press@alpha.com',
            'destination_email' => 'pr1@external.com',
            'is_active'         => true,
        ]);

        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $this->activeDomain->id,
            'local_part'        => 'press',
            'destination_email' => 'pr2@external.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'An alias with this source address already exists for this domain.');
    }

    public function test_cannot_create_alias_with_self_forwarding_direct_loop(): void
    {
        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $this->activeDomain->id,
            'local_part'        => 'loop',
            'destination_email' => 'loop@alpha.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Self-forwarding loop detected: source and destination email cannot be identical.');
    }

    public function test_cannot_create_alias_with_circular_forwarding_chain(): void
    {
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'step1@alpha.com',
            'destination_email' => 'step2@alpha.com',
            'is_active'         => true,
        ]);

        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'step2@alpha.com',
            'destination_email' => 'step3@alpha.com',
            'is_active'         => true,
        ]);

        // Attempting to forward step3 back to step1 should trigger chain loop detection
        $response = $this->actingAs($this->tenant)->postJson('/api/aliases', [
            'domain_id'         => $this->activeDomain->id,
            'local_part'        => 'step3',
            'destination_email' => 'step1@alpha.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Forwarding cycle detected: forwarding to this address would create an infinite email loop.');
    }

    public function test_cross_tenant_isolation_on_show_update_toggle_delete(): void
    {
        $otherDomain = Domain::create([
            'user_id'     => $this->otherTenant->id,
            'domain_name' => 'other.com',
            'status'      => 'active',
        ]);

        $otherAlias = EmailAlias::create([
            'domain_id'         => $otherDomain->id,
            'source_email'      => 'secret@other.com',
            'destination_email' => 'dest@other.com',
            'is_active'         => true,
        ]);

        // Tenant One cannot show Tenant Two's alias
        $this->actingAs($this->tenant)
            ->getJson('/api/aliases/' . $otherAlias->id)
            ->assertStatus(403);

        // Tenant One cannot update Tenant Two's alias
        $this->actingAs($this->tenant)
            ->putJson('/api/aliases/' . $otherAlias->id, [
                'destination_email' => 'hacked@external.com',
            ])
            ->assertStatus(403);

        // Tenant One cannot toggle Tenant Two's alias
        $this->actingAs($this->tenant)
            ->postJson('/api/aliases/' . $otherAlias->id . '/toggle')
            ->assertStatus(403);

        // Tenant One cannot delete Tenant Two's alias
        $this->actingAs($this->tenant)
            ->deleteJson('/api/aliases/' . $otherAlias->id)
            ->assertStatus(403);
    }

    public function test_tenant_can_update_alias_destination(): void
    {
        $alias = EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'legal@alpha.com',
            'destination_email' => 'old-lawyer@lawfirm.com',
            'is_active'         => true,
        ]);

        $response = $this->actingAs($this->tenant)
            ->putJson('/api/aliases/' . $alias->id, [
                'destination_email' => 'new-lawyer@lawfirm.com',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.source_email', 'legal@alpha.com')
            ->assertJsonPath('data.destination_email', 'new-lawyer@lawfirm.com');

        $this->assertDatabaseHas('email_aliases', [
            'id'                => $alias->id,
            'source_email'      => 'legal@alpha.com',
            'destination_email' => 'new-lawyer@lawfirm.com',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'alias.update',
            'entity_type'   => 'EmailAlias',
            'entity_id'     => $alias->id,
            'actor_user_id' => $this->tenant->id,
        ]);
    }

    public function test_update_prevents_loops(): void
    {
        $alias = EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'orders@alpha.com',
            'destination_email' => 'team@external.com',
            'is_active'         => true,
        ]);

        // Direct loop
        $this->actingAs($this->tenant)
            ->putJson('/api/aliases/' . $alias->id, [
                'destination_email' => 'orders@alpha.com',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Self-forwarding loop detected: source and destination email cannot be identical.');
    }

    public function test_tenant_can_toggle_alias_status(): void
    {
        $alias = EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'toggle@alpha.com',
            'destination_email' => 'dest@external.com',
            'is_active'         => true,
        ]);

        // Toggle to disabled
        $res1 = $this->actingAs($this->tenant)
            ->postJson('/api/aliases/' . $alias->id . '/toggle');

        $res1->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($alias->fresh()->is_active);

        // Toggle back to active
        $res2 = $this->actingAs($this->tenant)
            ->postJson('/api/aliases/' . $alias->id . '/toggle');

        $res2->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($alias->fresh()->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'alias.toggle',
            'entity_type'   => 'EmailAlias',
            'entity_id'     => $alias->id,
            'actor_user_id' => $this->tenant->id,
        ]);
    }

    public function test_toggle_enabling_blocked_if_parent_domain_inactive(): void
    {
        $alias = EmailAlias::create([
            'domain_id'         => $this->pendingDomain->id,
            'source_email'      => 'pending-alias@pending.com',
            'destination_email' => 'dest@external.com',
            'is_active'         => false,
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson('/api/aliases/' . $alias->id . '/toggle');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot enable alias on an unverified or inactive domain.');
    }

    public function test_tenant_can_delete_alias(): void
    {
        $alias = EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'temp@alpha.com',
            'destination_email' => 'dest@external.com',
            'is_active'         => true,
        ]);

        $response = $this->actingAs($this->tenant)
            ->deleteJson('/api/aliases/' . $alias->id);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('email_aliases', [
            'id' => $alias->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'alias.delete',
            'entity_type'   => 'EmailAlias',
            'entity_id'     => $alias->id,
            'actor_user_id' => $this->tenant->id,
        ]);
    }

    public function test_deleting_domain_cascades_and_removes_aliases(): void
    {
        $alias = EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'cascade@alpha.com',
            'destination_email' => 'dest@external.com',
            'is_active'         => true,
        ]);

        // Delete domain
        $this->actingAs($this->tenant)
            ->deleteJson('/api/domains/' . $this->activeDomain->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('email_aliases', [
            'id' => $alias->id,
        ]);
    }
}
