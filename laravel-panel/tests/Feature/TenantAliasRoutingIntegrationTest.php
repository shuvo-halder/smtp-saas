<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\EmailAlias;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\PostfixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantAliasRoutingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private User $tenant;
    private Domain $activeDomain;
    private Domain $pendingDomain;
    private PostfixService $postfixService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                              => 'Business Pro',
            'slug'                              => 'business-pro',
            'description'                       => 'Standard business plan',
            'max_domains'                       => 3,
            'max_mailboxes_per_domain'          => 10,
            'storage_mb_per_mailbox'            => 2048,
            'max_aliases_per_domain'            => 10,
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
            'name'            => 'Routing Tenant',
            'email'           => 'routing_tenant@example.com',
            'password'        => bcrypt('Password123!'),
            'company_name'    => 'Routing Corp',
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->addDays(30),
            'is_admin'        => false,
        ]);

        $this->activeDomain = Domain::create([
            'user_id'       => $this->tenant->id,
            'domain_name'   => 'routing-test.com',
            'status'        => 'active',
            'mx_verified'   => true,
            'spf_verified'  => true,
            'dkim_verified' => true,
        ]);

        $this->pendingDomain = Domain::create([
            'user_id'       => $this->tenant->id,
            'domain_name'   => 'unverified-test.com',
            'status'        => 'pending',
            'mx_verified'   => false,
            'spf_verified'  => false,
            'dkim_verified' => false,
        ]);

        $this->postfixService = app(PostfixService::class);
    }

    /**
     * Level A: API alias creation returns dynamic routing details and synchronized status.
     */
    public function test_api_alias_creation_returns_routing_details_and_synchronized_status(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')->postJson('/api/aliases', [
            'domain_id'         => $this->activeDomain->id,
            'local_part'        => 'contact',
            'destination_email' => 'external-inbox@gmail.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.source_email', 'contact@routing-test.com')
            ->assertJsonPath('data.destination_email', 'external-inbox@gmail.com')
            ->assertJsonPath('data.forwarding_status', 'synchronized')
            ->assertJsonPath('data.routing_details.is_routable', true)
            ->assertJsonPath('data.routing_details.terminal_target', 'external-inbox@gmail.com')
            ->assertJsonPath('data.routing_details.hop_count', 2)
            ->assertJsonPath('data.routing_details.mta_driver', 'postfix_mysql_map');
    }

    /**
     * Level A: Inactive alias or pending domain reports status as 'configured' and not routable.
     */
    public function test_inactive_alias_returns_configured_status(): void
    {
        $alias = EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'disabled@routing-test.com',
            'destination_email' => 'external@gmail.com',
            'is_active'         => false,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')->getJson('/api/aliases/' . $alias->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.forwarding_status', 'configured')
            ->assertJsonPath('data.routing_details.is_routable', false);

        $routing = $this->postfixService->verifyAliasRouting('disabled@routing-test.com');
        $this->assertFalse($routing['is_routable']);
        $this->assertEquals('configured', $routing['status']);
    }

    /**
     * Level A: Multi-hop resolution correctly traces chain to physical mailbox.
     */
    public function test_multi_hop_resolution_traces_chain_to_terminal_mailbox(): void
    {
        // 1. Create terminal physical mailbox
        Mailbox::create([
            'domain_id'   => $this->activeDomain->id,
            'local_part'  => 'terminal',
            'email'       => 'terminal@routing-test.com',
            'password'    => bcrypt('Secret123!'),
            'quota_bytes' => 104857600,
            'is_active'   => true,
        ]);

        // 2. Intermediate alias: intermediate@ -> terminal@
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'intermediate@routing-test.com',
            'destination_email' => 'terminal@routing-test.com',
            'is_active'         => true,
        ]);

        // 3. Entry alias: entry@ -> intermediate@
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'entry@routing-test.com',
            'destination_email' => 'intermediate@routing-test.com',
            'is_active'         => true,
        ]);

        $routing = $this->postfixService->verifyAliasRouting('entry@routing-test.com');

        $this->assertTrue($routing['is_routable']);
        $this->assertEquals('terminal@routing-test.com', $routing['terminal_target']);
        $this->assertEquals(3, $routing['hop_count']);
        $this->assertEquals([
            'entry@routing-test.com',
            'intermediate@routing-test.com',
            'terminal@routing-test.com',
        ], $routing['hops']);
        $this->assertFalse($routing['cycle_detected']);
        $this->assertNull($routing['warning']);
    }

    /**
     * Level A: Dangling local destination triggers 'failed' status and explicit warning.
     */
    public function test_dangling_local_destination_marks_status_failed_with_warning(): void
    {
        // Destination points to active hosted domain, but mailbox does not exist
        $alias = EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'dangling@routing-test.com',
            'destination_email' => 'ghost-mailbox@routing-test.com',
            'is_active'         => true,
        ]);

        $routing = $this->postfixService->verifyAliasRouting('dangling@routing-test.com');

        $this->assertFalse($routing['is_routable']);
        $this->assertEquals('failed', $routing['status']);
        $this->assertStringContainsString('does not correspond to an active mailbox', $routing['warning']);

        $response = $this->actingAs($this->tenant, 'sanctum')->getJson('/api/aliases/' . $alias->id);
        $response->assertStatus(200)
            ->assertJsonPath('data.forwarding_status', 'failed')
            ->assertJsonPath('data.routing_details.is_routable', false);
    }

    /**
     * Level A: Domain deletion cascades and invalidates Postfix routing lookup.
     */
    public function test_domain_deletion_invalidates_postfix_routing(): void
    {
        $alias = EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'ephemeral@routing-test.com',
            'destination_email' => 'external@gmail.com',
            'is_active'         => true,
        ]);

        $routingBefore = $this->postfixService->verifyAliasRouting('ephemeral@routing-test.com');
        $this->assertTrue($routingBefore['is_routable']);

        // Delete parent domain
        $this->activeDomain->delete();

        $routingAfter = $this->postfixService->verifyAliasRouting('ephemeral@routing-test.com');
        $this->assertFalse($routingAfter['is_routable']);
        $this->assertEquals('unknown', $routingAfter['status']);
    }

    /**
     * Level B: Exact SQL query from mysql-virtual-aliases.cf executes against database.
     */
    public function test_postfix_mysql_virtual_aliases_cf_query_matches_database(): void
    {
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'query-test@routing-test.com',
            'destination_email' => 'dest@gmail.com',
            'is_active'         => true,
        ]);

        // Exact query from postfix-config/mysql-virtual-aliases.cf
        $sql = "SELECT email_aliases.destination_email FROM email_aliases INNER JOIN domains ON email_aliases.domain_id = domains.id WHERE email_aliases.source_email = ? AND email_aliases.is_active = 1 AND domains.status = 'active' AND domains.deleted_at IS NULL";

        $result = DB::select($sql, ['query-test@routing-test.com']);
        $this->assertCount(1, $result);
        $this->assertEquals('dest@gmail.com', $result[0]->destination_email);

        // Deactivated alias returns empty
        DB::table('email_aliases')->where('source_email', 'query-test@routing-test.com')->update(['is_active' => false]);
        $resultInactive = DB::select($sql, ['query-test@routing-test.com']);
        $this->assertEmpty($resultInactive);

        // Alias on pending domain returns empty
        EmailAlias::create([
            'domain_id'         => $this->pendingDomain->id,
            'source_email'      => 'pending-alias@unverified-test.com',
            'destination_email' => 'dest@gmail.com',
            'is_active'         => true,
        ]);
        $resultPending = DB::select($sql, ['pending-alias@unverified-test.com']);
        $this->assertEmpty($resultPending);
    }

    /**
     * Level B: Exact SQL queries from virtual domains and virtual mailboxes .cf files.
     */
    public function test_postfix_mysql_virtual_domains_and_mailboxes_cf_queries(): void
    {
        Mailbox::create([
            'domain_id'   => $this->activeDomain->id,
            'local_part'  => 'info',
            'email'       => 'info@routing-test.com',
            'password'    => bcrypt('Secret123!'),
            'quota_bytes' => 104857600,
            'is_active'   => true,
        ]);

        // Virtual domains query from mysql-virtual-domains.cf
        $domainSql = "SELECT domain_name FROM domains WHERE domain_name = ? AND status = 'active' AND deleted_at IS NULL";
        $domainResult = DB::select($domainSql, ['routing-test.com']);
        $this->assertCount(1, $domainResult);
        $this->assertEquals('routing-test.com', $domainResult[0]->domain_name);

        $pendingDomainResult = DB::select($domainSql, ['unverified-test.com']);
        $this->assertEmpty($pendingDomainResult);

        // Virtual mailboxes query from mysql-virtual-mailboxes.cf
        $mailboxSql = "SELECT CONCAT(domains.domain_name, '/', mailboxes.local_part, '/') as maildir FROM mailboxes INNER JOIN domains ON mailboxes.domain_id = domains.id WHERE mailboxes.email = ? AND mailboxes.is_active = 1 AND domains.status = 'active' AND domains.deleted_at IS NULL AND mailboxes.deleted_at IS NULL";
        $mailboxResult = DB::select($mailboxSql, ['info@routing-test.com']);
        $this->assertCount(1, $mailboxResult);
        $this->assertEquals('routing-test.com/info/', $mailboxResult[0]->maildir);
    }

    /**
     * Level B: Artisan command mail:verify-alias runs cleanly and reports status.
     */
    public function test_artisan_command_mail_verify_alias(): void
    {
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'cli-test@routing-test.com',
            'destination_email' => 'cli-target@gmail.com',
            'is_active'         => true,
        ]);

        // Test single address argument
        $exitCode = \Illuminate\Support\Facades\Artisan::call('mail:verify-alias', [
            'source_email' => 'cli-test@routing-test.com',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('cli-test@routing-test.com', $output);
        $this->assertStringContainsString('Routing resolution check passed', $output);

        // Test --all flag
        $exitCodeAll = \Illuminate\Support\Facades\Artisan::call('mail:verify-alias', [
            '--all' => true,
        ]);
        $outputAll = \Illuminate\Support\Facades\Artisan::output();
        $this->assertEquals(0, $exitCodeAll);
        $this->assertStringContainsString('cli-test@routing-test.com', $outputAll);

        // Test failed routing alias exits with 1
        EmailAlias::create([
            'domain_id'         => $this->activeDomain->id,
            'source_email'      => 'broken-cli@routing-test.com',
            'destination_email' => 'ghost@routing-test.com',
            'is_active'         => true,
        ]);

        $exitCodeFailed = \Illuminate\Support\Facades\Artisan::call('mail:verify-alias', [
            'source_email' => 'broken-cli@routing-test.com',
        ]);
        $outputFailed = \Illuminate\Support\Facades\Artisan::output();
        $this->assertEquals(1, $exitCodeFailed);
        $this->assertStringContainsString('Routing resolution check failed', $outputFailed);
    }
}
