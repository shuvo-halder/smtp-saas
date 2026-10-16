<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\EmailAlias;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\PostfixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantCatchAllManagementTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private User $tenant;
    private User $otherTenant;
    private Domain $activeDomain;
    private Domain $pendingDomain;
    private Mailbox $mailbox1;
    private Mailbox $mailbox2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                              => 'Business Pro',
            'slug'                              => 'business-pro',
            'description'                       => 'Standard business plan',
            'max_domains'                       => 5,
            'max_mailboxes_per_domain'          => 10,
            'storage_mb_per_mailbox'            => 2048,
            'max_aliases_per_domain'            => 5,
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
            'status'          => 'active',
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        $this->otherTenant = User::create([
            'name'            => 'Tenant Two',
            'email'           => 'tenant2@example.com',
            'password'        => bcrypt('Password123!'),
            'company_name'    => 'Other Corp',
            'status'          => 'active',
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        $this->activeDomain = Domain::create([
            'user_id'       => $this->tenant->id,
            'domain_name'   => 'tenantcorp.com',
            'status'        => 'active',
            'mx_verified'   => true,
            'spf_verified'  => true,
            'dkim_verified' => true,
        ]);

        $this->pendingDomain = Domain::create([
            'user_id'       => $this->tenant->id,
            'domain_name'   => 'pendingcorp.com',
            'status'        => 'pending',
            'mx_verified'   => false,
            'spf_verified'  => false,
            'dkim_verified' => false,
        ]);

        $this->mailbox1 = Mailbox::create([
            'domain_id'    => $this->activeDomain->id,
            'local_part'   => 'info',
            'email'        => 'info@tenantcorp.com',
            'password'     => crypt('Secret123!', '$6$rounds=5000$salt$'),
            'display_name' => 'Info Team',
            'quota_mb'     => 1024,
            'is_active'    => true,
            'is_catchall'  => false,
        ]);

        $this->mailbox2 = Mailbox::create([
            'domain_id'    => $this->activeDomain->id,
            'local_part'   => 'support',
            'email'        => 'support@tenantcorp.com',
            'password'     => crypt('Secret123!', '$6$rounds=5000$salt$'),
            'display_name' => 'Support Desk',
            'quota_mb'     => 1024,
            'is_active'    => true,
            'is_catchall'  => false,
        ]);
    }

    /**
     * 1. Unauthenticated requests are rejected with HTTP 401.
     */
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/domains/' . $this->activeDomain->id . '/catch-all')
            ->assertStatus(401);

        $this->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
            'policy' => 'reject',
        ])->assertStatus(401);
    }

    /**
     * 2. Strict tenant isolation: Tenant cannot view or edit another tenant's domain catch-all.
     */
    public function test_cross_tenant_isolation_is_strictly_enforced(): void
    {
        // Tenant Two tries to view Tenant One's domain catch-all
        $this->actingAs($this->otherTenant, 'sanctum')
            ->getJson('/api/domains/' . $this->activeDomain->id . '/catch-all')
            ->assertStatus(403);

        // Tenant Two tries to mutate Tenant One's domain catch-all
        $this->actingAs($this->otherTenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy' => 'reject',
            ])->assertStatus(403);
    }

    /**
     * 3. Default catch-all policy is securely disabled.
     */
    public function test_default_catch_all_policy_is_securely_disabled(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/domains/' . $this->activeDomain->id . '/catch-all');

        $response->assertStatus(200)
            ->assertJsonPath('data.policy', 'disabled')
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.mailbox', null)
            ->assertJsonPath('data.routing.action', 'reject')
            ->assertJsonPath('data.routing.is_routable', false);
    }

    /**
     * 4. Policy validation: rejects unsupported or invalid policies.
     */
    public function test_supported_policy_validation(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy' => 'forward_all_external',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['policy']);
    }

    /**
     * 5. Mailbox policy requires mailbox_id.
     */
    public function test_mailbox_policy_requires_mailbox_id(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy'     => 'mailbox',
                'mailbox_id' => null,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mailbox_id']);
    }

    /**
     * 6. Destination mailbox must belong to the same domain.
     */
    public function test_destination_mailbox_must_belong_to_same_domain(): void
    {
        $otherDomain = Domain::create([
            'user_id'       => $this->otherTenant->id,
            'domain_name'   => 'otherdomain.com',
            'status'        => 'active',
            'mx_verified'   => true,
            'spf_verified'  => true,
            'dkim_verified' => true,
        ]);

        $foreignMailbox = Mailbox::create([
            'domain_id'    => $otherDomain->id,
            'local_part'   => 'ceo',
            'email'        => 'ceo@otherdomain.com',
            'password'     => crypt('Secret123!', '$6$rounds=5000$salt$'),
            'display_name' => 'Foreign CEO',
            'quota_mb'     => 1024,
            'is_active'    => true,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy'     => 'mailbox',
                'mailbox_id' => $foreignMailbox->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mailbox_id']);
    }

    /**
     * 7. Inactive mailbox cannot be designated as catch-all.
     */
    public function test_inactive_mailbox_cannot_be_designated_catch_all(): void
    {
        $this->mailbox1->update(['is_active' => false]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy'     => 'mailbox',
                'mailbox_id' => $this->mailbox1->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mailbox_id']);
    }

    /**
     * 8. Pending or unverified domain rejects enabling catch-all.
     */
    public function test_pending_domain_rejects_enabling_catch_all(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->pendingDomain->id . '/catch-all', [
                'policy' => 'discard',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['policy']);
    }

    /**
     * 9. Inactive or suspended tenant subscription rejects enabling catch-all.
     */
    public function test_suspended_subscription_rejects_enabling_catch_all(): void
    {
        $this->tenant->update([
            'status'          => 'suspended',
            'plan_expires_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy' => 'discard',
            ]);

        $response->assertStatus(403);
    }

    /**
     * 10. Enable catch-all mailbox policy and sync is_catchall flag.
     */
    public function test_enable_catch_all_mailbox_policy_and_sync_is_catchall_flag(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy'     => 'mailbox',
                'mailbox_id' => $this->mailbox1->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.policy', 'mailbox')
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.mailbox.id', $this->mailbox1->id)
            ->assertJsonPath('data.mailbox.email', 'info@tenantcorp.com')
            ->assertJsonPath('data.routing.is_routable', true)
            ->assertJsonPath('data.routing.action', 'mailbox')
            ->assertJsonPath('data.routing.destination', 'info@tenantcorp.com');

        $this->activeDomain->refresh();
        $this->assertEquals('mailbox', $this->activeDomain->catch_all_policy);
        $this->assertEquals($this->mailbox1->id, $this->activeDomain->catch_all_mailbox_id);

        $this->mailbox1->refresh();
        $this->mailbox2->refresh();
        $this->assertTrue((bool) $this->mailbox1->is_catchall);
        $this->assertFalse((bool) $this->mailbox2->is_catchall);

        // Switch to mailbox 2
        $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy'     => 'mailbox',
                'mailbox_id' => $this->mailbox2->id,
            ])->assertStatus(200);

        $this->mailbox1->refresh();
        $this->mailbox2->refresh();
        $this->assertFalse((bool) $this->mailbox1->is_catchall);
        $this->assertTrue((bool) $this->mailbox2->is_catchall);
    }

    /**
     * 11. Disabling catch-all clears mailbox destination and is_catchall flags.
     */
    public function test_disable_catch_all_clears_is_catchall_flags(): void
    {
        // First enable mailbox policy
        $this->activeDomain->update([
            'catch_all_policy'     => 'mailbox',
            'catch_all_mailbox_id' => $this->mailbox1->id,
        ]);
        $this->mailbox1->update(['is_catchall' => true]);

        // Now disable
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy' => 'disabled',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.policy', 'disabled')
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.mailbox', null);

        $this->activeDomain->refresh();
        $this->assertEquals('disabled', $this->activeDomain->catch_all_policy);
        $this->assertNull($this->activeDomain->catch_all_mailbox_id);

        $this->mailbox1->refresh();
        $this->assertFalse((bool) $this->mailbox1->is_catchall);
    }

    /**
     * 12. Discard policy sets devnull destination.
     */
    public function test_discard_policy_sets_devnull_destination(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy' => 'discard',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.policy', 'discard')
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.mailbox', null)
            ->assertJsonPath('data.routing.action', 'discard')
            ->assertJsonPath('data.routing.destination', 'devnull@localhost');

        $this->activeDomain->refresh();
        $this->assertEquals('discard', $this->activeDomain->catch_all_policy);
        $this->assertNull($this->activeDomain->catch_all_mailbox_id);
    }

    /**
     * 13. Precedence: Physical mailbox is NOT overridden by catch-all in Postfix map.
     */
    public function test_physical_mailbox_precedence_over_catch_all(): void
    {
        $this->activeDomain->update([
            'catch_all_policy'     => 'mailbox',
            'catch_all_mailbox_id' => $this->mailbox1->id,
        ]);

        // Simulate lookup for existing physical mailbox 'support@tenantcorp.com'
        $recipient = 'support@tenantcorp.com';
        $domainName = 'tenantcorp.com';

        // Execute exact Postfix mysql-virtual-catchall.cf query
        $match = DB::table('domains as d')
            ->leftJoin('mailboxes as m_dest', 'd.catch_all_mailbox_id', '=', 'm_dest.id')
            ->where('d.domain_name', $domainName)
            ->where('d.status', 'active')
            ->whereNull('d.deleted_at')
            ->where(function ($query) {
                $query->where(function ($sub) {
                    $sub->where('d.catch_all_policy', 'mailbox')
                        ->whereNotNull('m_dest.id')
                        ->where('m_dest.is_active', 1)
                        ->whereNull('m_dest.deleted_at');
                })->orWhere('d.catch_all_policy', 'discard');
            })
            ->whereNotExists(function ($sub) use ($recipient) {
                $sub->select(DB::raw(1))
                    ->from('mailboxes as m_existing')
                    ->where('m_existing.email', $recipient)
                    ->where('m_existing.is_active', 1)
                    ->whereNull('m_existing.deleted_at');
            })
            ->whereNotExists(function ($sub) use ($recipient) {
                $sub->select(DB::raw(1))
                    ->from('email_aliases as a_existing')
                    ->where('a_existing.source_email', $recipient)
                    ->where('a_existing.is_active', 1);
            })
            ->select(DB::raw("CASE WHEN d.catch_all_policy = 'mailbox' THEN m_dest.email WHEN d.catch_all_policy = 'discard' THEN 'devnull@localhost' ELSE NULL END as destination"))
            ->first();

        // Must be null! Catch-all must NOT match existing physical mailboxes!
        $this->assertNull($match);
    }

    /**
     * 14. Precedence: Explicit alias is NOT overridden by catch-all in Postfix map.
     */
    public function test_explicit_alias_precedence_over_catch_all(): void
    {
        $this->activeDomain->update([
            'catch_all_policy'     => 'mailbox',
            'catch_all_mailbox_id' => $this->mailbox1->id,
        ]);

        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'billing@tenantcorp.com',
            'destination_email' => 'accounting@external.com',
            'is_active'         => true,
        ]);

        $recipient = 'billing@tenantcorp.com';
        $domainName = 'tenantcorp.com';

        // Execute exact Postfix mysql-virtual-catchall.cf query
        $match = DB::table('domains as d')
            ->leftJoin('mailboxes as m_dest', 'd.catch_all_mailbox_id', '=', 'm_dest.id')
            ->where('d.domain_name', $domainName)
            ->where('d.status', 'active')
            ->whereNull('d.deleted_at')
            ->where(function ($query) {
                $query->where(function ($sub) {
                    $sub->where('d.catch_all_policy', 'mailbox')
                        ->whereNotNull('m_dest.id')
                        ->where('m_dest.is_active', 1)
                        ->whereNull('m_dest.deleted_at');
                })->orWhere('d.catch_all_policy', 'discard');
            })
            ->whereNotExists(function ($sub) use ($recipient) {
                $sub->select(DB::raw(1))
                    ->from('mailboxes as m_existing')
                    ->where('m_existing.email', $recipient)
                    ->where('m_existing.is_active', 1)
                    ->whereNull('m_existing.deleted_at');
            })
            ->whereNotExists(function ($sub) use ($recipient) {
                $sub->select(DB::raw(1))
                    ->from('email_aliases as a_existing')
                    ->where('a_existing.source_email', $recipient)
                    ->where('a_existing.is_active', 1);
            })
            ->select(DB::raw("CASE WHEN d.catch_all_policy = 'mailbox' THEN m_dest.email WHEN d.catch_all_policy = 'discard' THEN 'devnull@localhost' ELSE NULL END as destination"))
            ->first();

        // Must be null! Explicit alias takes precedence!
        $this->assertNull($match);
    }

    /**
     * 15. Unknown recipient routes to designated catch-all mailbox.
     */
    public function test_unknown_recipient_routes_to_catch_all_mailbox(): void
    {
        $this->activeDomain->update([
            'catch_all_policy'     => 'mailbox',
            'catch_all_mailbox_id' => $this->mailbox1->id,
        ]);

        $recipient = 'unregistered_address@tenantcorp.com';
        $domainName = 'tenantcorp.com';

        $match = DB::table('domains as d')
            ->leftJoin('mailboxes as m_dest', 'd.catch_all_mailbox_id', '=', 'm_dest.id')
            ->where('d.domain_name', $domainName)
            ->where('d.status', 'active')
            ->whereNull('d.deleted_at')
            ->where(function ($query) {
                $query->where(function ($sub) {
                    $sub->where('d.catch_all_policy', 'mailbox')
                        ->whereNotNull('m_dest.id')
                        ->where('m_dest.is_active', 1)
                        ->whereNull('m_dest.deleted_at');
                })->orWhere('d.catch_all_policy', 'discard');
            })
            ->whereNotExists(function ($sub) use ($recipient) {
                $sub->select(DB::raw(1))
                    ->from('mailboxes as m_existing')
                    ->where('m_existing.email', $recipient)
                    ->where('m_existing.is_active', 1)
                    ->whereNull('m_existing.deleted_at');
            })
            ->whereNotExists(function ($sub) use ($recipient) {
                $sub->select(DB::raw(1))
                    ->from('email_aliases as a_existing')
                    ->where('a_existing.source_email', $recipient)
                    ->where('a_existing.is_active', 1);
            })
            ->select(DB::raw("CASE WHEN d.catch_all_policy = 'mailbox' THEN m_dest.email WHEN d.catch_all_policy = 'discard' THEN 'devnull@localhost' ELSE NULL END as destination"))
            ->first();

        $this->assertNotNull($match);
        $this->assertEquals('info@tenantcorp.com', $match->destination);
    }

    /**
     * 16. Mailbox deletion resets domain catch-all to disabled if it was the catch-all mailbox.
     */
    public function test_mailbox_deletion_resets_domain_catch_all(): void
    {
        $this->activeDomain->update([
            'catch_all_policy'     => 'mailbox',
            'catch_all_mailbox_id' => $this->mailbox1->id,
        ]);

        $this->actingAs($this->tenant, 'sanctum')
            ->deleteJson('/api/mailboxes/' . $this->mailbox1->id)
            ->assertStatus(204);

        $this->activeDomain->refresh();
        $this->assertEquals('disabled', $this->activeDomain->catch_all_policy);
        $this->assertNull($this->activeDomain->catch_all_mailbox_id);
    }

    /**
     * 17. Domain deletion cascades cleanly.
     */
    public function test_domain_deletion_cascades_cleanly(): void
    {
        $this->activeDomain->update([
            'catch_all_policy'     => 'mailbox',
            'catch_all_mailbox_id' => $this->mailbox1->id,
        ]);

        $this->actingAs($this->tenant, 'sanctum')
            ->deleteJson('/api/domains/' . $this->activeDomain->id)
            ->assertStatus(204);

        $this->assertSoftDeleted('domains', ['id' => $this->activeDomain->id]);
    }

    /**
     * 18. Audit event is recorded on catch-all update.
     */
    public function test_audit_event_is_recorded_on_catch_all_update(): void
    {
        $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/domains/' . $this->activeDomain->id . '/catch-all', [
                'policy'     => 'mailbox',
                'mailbox_id' => $this->mailbox1->id,
            ])->assertStatus(200);

        $audit = AuditLog::where('action', 'domain.catch_all_update')
            ->where('entity_id', $this->activeDomain->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('Domain', $audit->entity_type);
        $this->assertEquals($this->tenant->email, $audit->actor_email);
        $this->assertEquals('mailbox', $audit->after_state['catch_all_policy']);
        $this->assertEquals('info@tenantcorp.com', $audit->after_state['mailbox_email']);
    }

    /**
     * 19. CLI verification command runs cleanly for catch-all diagnostics.
     */
    public function test_cli_verify_catch_all_command(): void
    {
        $this->activeDomain->update([
            'catch_all_policy'     => 'mailbox',
            'catch_all_mailbox_id' => $this->mailbox1->id,
        ]);

        $this->artisan('mail:verify-catchall', ['--all' => true])
            ->assertExitCode(0);

        $this->artisan('mail:verify-catchall', ['domain' => 'tenantcorp.com'])
            ->assertExitCode(0);
    }
}
