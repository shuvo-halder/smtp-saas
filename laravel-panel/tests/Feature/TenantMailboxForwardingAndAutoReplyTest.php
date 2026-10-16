<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\EmailAlias;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\MailboxRuleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantMailboxForwardingAndAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected Domain $domain;
    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create([
            'name'                        => 'Starter',
            'slug'                        => 'starter',
            'max_domains'                 => 5,
            'max_mailboxes_per_domain'    => 10,
            'max_aliases_per_domain'      => 20,
            'storage_mb_per_mailbox'      => 1024,
            'price_monthly'               => 10.00,
            'price_yearly'                => 100.00,
            'daily_outbound_recipients'   => 500,
            'mailbox_daily_outbound_recipients' => 100,
            'is_active'                   => true,
        ]);

        $this->tenant = User::create([
            'name'             => 'Test Tenant',
            'email'            => 'tenant@example.com',
            'password'         => bcrypt('password123'),
            'status'           => 'active',
            'plan_id'          => $plan->id,
            'plan_expires_at'  => now()->addDays(30),
            'is_admin'         => false,
        ]);

        $this->domain = Domain::create([
            'user_id'          => $this->tenant->id,
            'domain_name'      => 'tenantdomain.com',
            'status'           => 'active',
            'mx_verified'      => true,
            'spf_verified'     => true,
            'dkim_verified'    => true,
            'dmarc_verified'   => true,
            'catch_all_policy' => 'disabled',
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id'        => $this->domain->id,
            'local_part'       => 'alice',
            'email'            => 'alice@tenantdomain.com',
            'password'         => 'encrypted-hash',
            'quota_mb'         => 1024,
            'is_active'        => true,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson("/api/mailboxes/{$this->mailbox->id}/rules")
            ->assertStatus(401);

        $this->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
            'is_forwarding_enabled' => true,
            'destinations'          => ['backup@example.com'],
        ])->assertStatus(401);

        $this->putJson("/api/mailboxes/{$this->mailbox->id}/auto-reply", [
            'auto_reply_enabled' => true,
            'subject'            => 'Out of office',
            'body'               => 'I am away',
        ])->assertStatus(401);
    }

    public function test_suspended_tenant_cannot_manage_mailbox_rules(): void
    {
        $this->tenant->update([
            'status'          => 'suspended',
            'plan_expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['backup@example.com'],
            ])
            ->assertStatus(403);
    }

    public function test_cross_tenant_mailbox_isolation_enforced(): void
    {
        $otherTenant = User::create([
            'name'            => 'Other Tenant',
            'email'           => 'other@example.com',
            'password'        => bcrypt('password123'),
            'status'          => 'active',
            'plan_id'         => $this->tenant->plan_id,
            'plan_expires_at' => now()->addDays(30),
            'is_admin'        => false,
        ]);

        $this->actingAs($otherTenant, 'sanctum')
            ->getJson("/api/mailboxes/{$this->mailbox->id}/rules")
            ->assertStatus(403);

        $this->actingAs($otherTenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['hacker@example.com'],
            ])
            ->assertStatus(403);
    }

    public function test_cannot_configure_rules_on_inactive_or_unverified_domain(): void
    {
        $this->domain->update(['status' => 'pending']);

        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['backup@example.com'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot configure forwarding on an inactive or unverified domain.']);
    }

    public function test_get_mailbox_rules_returns_default_configuration(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson("/api/mailboxes/{$this->mailbox->id}/rules")
            ->assertStatus(200);

        $response->assertJson([
            'data' => [
                'mailbox_id'  => $this->mailbox->id,
                'email'       => 'alice@tenantdomain.com',
                'forwarding'  => [
                    'is_enabled'     => false,
                    'destinations'   => [],
                    'keep_copy'      => true,
                    'is_routable'    => false,
                    'status'         => 'configured',
                ],
                'auto_reply'  => [
                    'is_enabled'     => false,
                    'schedule_status'=> 'disabled',
                ],
                'sieve'       => [
                    'syntax_valid'   => true,
                    'driver'         => 'dovecot_sieve_pigeonhole',
                ],
            ]
        ]);

        $this->assertStringContainsString('keep;', $response->json('data.sieve.script_preview'));
    }

    public function test_can_enable_forwarding_with_valid_destination(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['backup@external.com'],
                'keep_copy'             => true,
            ])
            ->assertStatus(200);

        $response->assertJson([
            'data' => [
                'forwarding' => [
                    'is_enabled'   => true,
                    'destinations' => ['backup@external.com'],
                    'keep_copy'    => true,
                    'is_routable'  => true,
                    'status'       => 'synchronized',
                ]
            ]
        ]);

        $this->mailbox->refresh();
        $this->assertTrue($this->mailbox->is_forwarding_enabled);
        $this->assertEquals('backup@external.com', $this->mailbox->forwarding_destinations);
        $this->assertTrue($this->mailbox->forwarding_keep_copy);
    }

    public function test_can_enable_forwarding_with_multiple_destinations(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['dest1@example.com', 'dest2@example.com'],
                'keep_copy'             => false,
            ])
            ->assertStatus(200);

        $this->mailbox->refresh();
        $this->assertTrue($this->mailbox->is_forwarding_enabled);
        $this->assertEquals(['dest1@example.com', 'dest2@example.com'], $this->mailbox->getForwardingDestinationsList());
        $this->assertFalse($this->mailbox->forwarding_keep_copy);
    }

    public function test_forwarding_destinations_are_deduplicated_and_lowercased(): void
    {
        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['DUPLICATE@example.com', 'duplicate@example.com', 'OTHER@example.com'],
            ])
            ->assertStatus(200);

        $this->mailbox->refresh();
        $this->assertEquals(['duplicate@example.com', 'other@example.com'], $this->mailbox->getForwardingDestinationsList());
    }

    public function test_rejects_self_forwarding_loop(): void
    {
        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['alice@tenantdomain.com'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Self-forwarding loop detected: mailbox cannot forward to its own address.']);
    }

    public function test_rejects_circular_forwarding_chain_between_mailboxes(): void
    {
        $bob = Mailbox::create([
            'domain_id'               => $this->domain->id,
            'local_part'              => 'bob',
            'email'                   => 'bob@tenantdomain.com',
            'password'                => 'hash',
            'quota_mb'                => 1024,
            'is_active'               => true,
            'is_forwarding_enabled'   => true,
            'forwarding_destinations' => 'alice@tenantdomain.com',
            'forwarding_keep_copy'    => true,
        ]);

        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['bob@tenantdomain.com'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Circular forwarding chain detected: alice@tenantdomain.com -> bob@tenantdomain.com -> alice@tenantdomain.com']);
    }

    public function test_rejects_circular_forwarding_chain_through_email_alias(): void
    {
        EmailAlias::create([
            'domain_id'         => $this->domain->id,
            'source_email'      => 'info@tenantdomain.com',
            'destination_email' => 'alice@tenantdomain.com',
            'is_active'         => true,
        ]);

        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['info@tenantdomain.com'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Circular forwarding chain detected: alice@tenantdomain.com -> info@tenantdomain.com -> alice@tenantdomain.com']);
    }

    public function test_can_disable_forwarding(): void
    {
        $this->mailbox->update([
            'is_forwarding_enabled'   => true,
            'forwarding_destinations' => 'dest@example.com',
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => false,
                'destinations'          => [],
            ])
            ->assertStatus(200);

        $this->mailbox->refresh();
        $this->assertFalse($this->mailbox->is_forwarding_enabled);
    }

    public function test_can_enable_auto_reply_with_subject_and_body(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/auto-reply", [
                'auto_reply_enabled' => true,
                'subject'            => 'Out of Office Notice',
                'body'               => 'I am on annual leave until next Monday.',
            ])
            ->assertStatus(200);

        $response->assertJson([
            'data' => [
                'auto_reply' => [
                    'is_enabled'          => true,
                    'subject'             => 'Out of Office Notice',
                    'body'                => 'I am on annual leave until next Monday.',
                    'schedule_status'     => 'active',
                    'is_currently_active' => true,
                ]
            ]
        ]);

        $this->mailbox->refresh();
        $this->assertTrue($this->mailbox->auto_reply_enabled);
        $this->assertEquals('Out of Office Notice', $this->mailbox->auto_reply_subject);

        // Verify generated Sieve script
        $sieve = $response->json('data.sieve.script_preview');
        $this->assertStringContainsString('vacation', $sieve);
        $this->assertStringContainsString('Out of Office Notice', $sieve);
        $this->assertStringContainsString('I am on annual leave until next Monday.', $sieve);
        $this->assertStringContainsString('Auto-Submitted', $sieve);
        $this->assertStringContainsString('Precedence', $sieve);
    }

    public function test_rejects_auto_reply_without_subject_or_body(): void
    {
        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/auto-reply", [
                'auto_reply_enabled' => true,
                'subject'            => '',
                'body'               => 'Hello',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject']);

        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/auto-reply", [
                'auto_reply_enabled' => true,
                'subject'            => 'Out of Office',
                'body'               => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_rejects_invalid_auto_reply_date_range(): void
    {
        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/auto-reply", [
                'auto_reply_enabled' => true,
                'subject'            => 'Vacation',
                'body'               => 'Out of office',
                'start_at'           => now()->addDays(5)->toDateTimeString(),
                'end_at'             => now()->addDays(2)->toDateTimeString(),
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'End date must be after the start date.']);
    }

    public function test_auto_reply_body_strips_html_and_script_tags(): void
    {
        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/auto-reply", [
                'auto_reply_enabled' => true,
                'subject'            => 'Notice',
                'body'               => '<script>alert("xss")</script>Hello <b>World</b>',
            ])
            ->assertStatus(200);

        $this->mailbox->refresh();
        $this->assertEquals('alert("xss")Hello World', $this->mailbox->auto_reply_body);
        $this->assertStringNotContainsString('<script>', $this->mailbox->auto_reply_body);
    }

    public function test_auto_reply_schedule_status_calculation(): void
    {
        // 1. Future schedule -> scheduled
        $this->mailbox->update([
            'auto_reply_enabled'  => true,
            'auto_reply_subject'  => 'Future Vacation',
            'auto_reply_body'     => 'Away soon',
            'auto_reply_start_at' => now()->addDays(2),
            'auto_reply_end_at'   => now()->addDays(5),
        ]);
        $this->assertEquals('scheduled', $this->mailbox->autoReplyScheduleStatus());
        $this->assertFalse($this->mailbox->isAutoReplyActive());

        // 2. Currently active schedule -> active
        $this->mailbox->update([
            'auto_reply_start_at' => now()->subDay(),
            'auto_reply_end_at'   => now()->addDays(2),
        ]);
        $this->assertEquals('active', $this->mailbox->autoReplyScheduleStatus());
        $this->assertTrue($this->mailbox->isAutoReplyActive());

        // 3. Past schedule -> expired
        $this->mailbox->update([
            'auto_reply_start_at' => now()->subDays(5),
            'auto_reply_end_at'   => now()->subDay(),
        ]);
        $this->assertEquals('expired', $this->mailbox->autoReplyScheduleStatus());
        $this->assertFalse($this->mailbox->isAutoReplyActive());
    }

    public function test_audit_logs_recorded_on_rule_mutations(): void
    {
        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/forwarding", [
                'is_forwarding_enabled' => true,
                'destinations'          => ['audit@example.com'],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'mailbox.forwarding_update',
            'entity_type' => 'Mailbox',
            'entity_id'   => $this->mailbox->id,
            'actor_email' => $this->tenant->email,
        ]);

        $this->actingAs($this->tenant, 'sanctum')
            ->putJson("/api/mailboxes/{$this->mailbox->id}/auto-reply", [
                'auto_reply_enabled' => true,
                'subject'            => 'Audit Subject',
                'body'               => 'Audit Body',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'mailbox.auto_reply_update',
            'entity_type' => 'Mailbox',
            'entity_id'   => $this->mailbox->id,
            'actor_email' => $this->tenant->email,
        ]);
    }

    public function test_postfix_virtual_forwarding_sql_resolution(): void
    {
        // 1. With keep_copy = true: returns "email, destination"
        $this->mailbox->update([
            'is_forwarding_enabled'   => true,
            'forwarding_destinations' => 'dest1@example.com, dest2@example.com',
            'forwarding_keep_copy'    => true,
        ]);

        $sql = "SELECT CASE WHEN mailboxes.forwarding_keep_copy = 1 THEN CONCAT(mailboxes.email, ', ', mailboxes.forwarding_destinations) ELSE mailboxes.forwarding_destinations END AS destination FROM mailboxes INNER JOIN domains ON mailboxes.domain_id = domains.id WHERE mailboxes.email = ? AND mailboxes.is_forwarding_enabled = 1 AND mailboxes.is_active = 1 AND domains.status = 'active' AND mailboxes.deleted_at IS NULL AND domains.deleted_at IS NULL LIMIT 1";

        $res1 = DB::select($sql, ['alice@tenantdomain.com']);
        $this->assertCount(1, $res1);
        $this->assertEquals('alice@tenantdomain.com, dest1@example.com, dest2@example.com', $res1[0]->destination);

        // 2. With keep_copy = false: returns only destinations
        $this->mailbox->update(['forwarding_keep_copy' => false]);
        $res2 = DB::select($sql, ['alice@tenantdomain.com']);
        $this->assertCount(1, $res2);
        $this->assertEquals('dest1@example.com, dest2@example.com', $res2[0]->destination);

        // 3. With is_forwarding_enabled = false: returns 0 rows
        $this->mailbox->update(['is_forwarding_enabled' => false]);
        $res3 = DB::select($sql, ['alice@tenantdomain.com']);
        $this->assertCount(0, $res3);
    }

    public function test_mailbox_resource_includes_forwarding_and_auto_reply_flags(): void
    {
        $this->mailbox->update([
            'is_forwarding_enabled' => true,
            'auto_reply_enabled'    => true,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson("/api/mailboxes/{$this->mailbox->id}")
            ->assertStatus(200);

        $response->assertJson([
            'data' => [
                'id'                    => $this->mailbox->id,
                'email'                 => 'alice@tenantdomain.com',
                'is_forwarding_enabled' => true,
                'auto_reply_enabled'    => true,
            ]
        ]);
    }
}
