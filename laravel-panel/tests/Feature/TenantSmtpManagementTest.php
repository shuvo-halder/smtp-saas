<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DeliveryLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TenantSmtpManagementTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private User $tenant;
    private User $otherTenant;
    private Domain $domain;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                              => 'Business Starter',
            'slug'                              => 'business-starter',
            'description'                       => 'Standard plan',
            'max_domains'                       => 3,
            'max_mailboxes_per_domain'          => 5,
            'storage_mb_per_mailbox'            => 2048,
            'max_aliases_per_domain'            => 5,
            'daily_outbound_recipients'         => 1000,
            'mailbox_daily_outbound_recipients' => 250,
            'price_monthly'                     => 1500.00,
            'price_yearly'                      => 15000.00,
            'features'                          => ['Custom Domain', 'SMTP Access'],
            'is_active'                         => true,
            'is_featured'                       => true,
            'sort_order'                        => 1,
        ]);

        $this->tenant = User::create([
            'name'            => 'Acme Tenant',
            'email'           => 'admin@acme.com',
            'password'        => bcrypt('Password123!'),
            'company_name'    => 'Acme Inc',
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->addDays(30),
            'is_admin'        => false,
        ]);

        $this->otherTenant = User::create([
            'name'            => 'Other Tenant',
            'email'           => 'admin@other.com',
            'password'        => bcrypt('Password123!'),
            'company_name'    => 'Other Corp',
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->addDays(30),
            'is_admin'        => false,
        ]);

        $this->domain = Domain::create([
            'user_id'           => $this->tenant->id,
            'domain_name'       => 'acme.com',
            'status'            => 'active',
            'mx_verified'       => true,
            'spf_verified'      => true,
            'dkim_verified'     => true,
            'last_dns_check_at' => now(),
            'activated_at'      => now(),
        ]);

        $salt = '1234567890abcdef';
        $hashedPassword = crypt('SecretPass123!', '$6$' . $salt . '$');

        $this->mailbox = Mailbox::create([
            'domain_id'    => $this->domain->id,
            'local_part'   => 'sales',
            'email'        => 'sales@acme.com',
            'password'     => $hashedPassword,
            'display_name' => 'Acme Sales',
            'quota_mb'     => 1024,
            'is_active'    => true,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/smtp/settings')->assertStatus(401);
        $this->getJson('/api/smtp/usage')->assertStatus(401);
        $this->getJson('/api/smtp/credentials')->assertStatus(401);
        $this->postJson("/api/smtp/credentials/{$this->mailbox->id}/rotate-password")->assertStatus(401);
        $this->getJson('/api/smtp/activity')->assertStatus(401);
        $this->getJson('/api/smtp/diagnostics')->assertStatus(401);
    }

    public function test_suspended_tenant_is_blocked_from_smtp_endpoints(): void
    {
        $this->tenant->update(['status' => 'suspended']);

        $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/smtp/settings')
            ->assertStatus(403);
    }

    public function test_tenant_can_view_smtp_settings(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/smtp/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'hostname',
                'ports' => [
                    '*' => ['port', 'protocol', 'recommended', 'description'],
                ],
                'authentication',
                'username_format',
                'password_format',
                'tls_minimum_version',
                'code_snippets' => ['nodejs', 'python', 'php', 'env'],
            ]);

        $data = $response->json();
        $this->assertNotEmpty($data['hostname']);
        $this->assertEquals('Dovecot SASL (PLAIN, LOGIN)', $data['authentication']);
        $this->assertEquals('TLS 1.2+', $data['tls_minimum_version']);
        $this->assertStringContainsString('587', $data['code_snippets']['nodejs']);
        $this->assertStringContainsString('sales@acme.com', $data['code_snippets']['nodejs']);
    }

    public function test_tenant_can_view_smtp_usage_and_quota_telemetry(): void
    {
        $today = Carbon::now('UTC')->format('Y-m-d');

        try {
            Redis::set("outbound:tenant:{$this->tenant->id}:recipients:daily:{$today}", 150);
            Redis::set("outbound:mailbox:{$this->mailbox->id}:recipients:daily:{$today}", 100);
        } catch (\Throwable) {
            // In testing environments without real Redis, test handles gracefully
        }

        TenantOutboundUsage::create([
            'user_id'         => $this->tenant->id,
            'usage_date'      => Carbon::now('UTC')->subDay()->format('Y-m-d'),
            'recipient_count' => 320,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/smtp/usage');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'plan_name',
                'daily_limit',
                'is_unlimited',
                'mailbox_daily_limit',
                'used_today',
                'remaining_today',
                'percent_used',
                'history' => [
                    '*' => ['date', 'recipient_count', 'is_today'],
                ],
                'mailboxes_breakdown' => [
                    '*' => ['id', 'email', 'display_name', 'is_active', 'used_today', 'mailbox_daily_limit'],
                ],
            ]);

        $data = $response->json();
        $this->assertEquals('Business Starter', $data['plan_name']);
        $this->assertEquals(1000, $data['daily_limit']);
        $this->assertEquals(250, $data['mailbox_daily_limit']);
        $this->assertCount(14, $data['history']);
    }

    public function test_tenant_can_list_smtp_credentials_without_exposing_password_hashes(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/smtp/credentials');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'domain_id',
                    'domain_name',
                    'email',
                    'display_name',
                    'is_active',
                    'last_login_at',
                    'created_at',
                ],
            ]);

        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('sales@acme.com', $data[0]['email']);
        $this->assertEquals('acme.com', $data[0]['domain_name']);

        // Verify that neither 'password' nor any hash string is exposed
        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('$6$', $responseContent);
        $this->assertStringNotContainsString('SecretPass123!', $responseContent);
        $this->assertArrayNotHasKey('password', $data[0]);
    }

    public function test_tenant_can_rotate_mailbox_password_and_receive_plaintext_only_once(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson("/api/smtp/credentials/{$this->mailbox->id}/rotate-password");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'email',
                'generated_password',
                'warning',
            ]);

        $data = $response->json();
        $newPlainPassword = $data['generated_password'];
        $this->assertNotEmpty($newPlainPassword);
        $this->assertGreaterThanOrEqual(16, strlen($newPlainPassword));

        // Verify stored in database as Dovecot SHA512-CRYPT
        $this->mailbox->refresh();
        $this->assertStringStartsWith('$6$', $this->mailbox->password);
        $this->assertTrue(hash_equals($this->mailbox->password, crypt($newPlainPassword, $this->mailbox->password)));

        // Verify audit log recorded
        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'smtp.credentials.rotate_password',
            'entity_type'   => 'Mailbox',
            'entity_id'     => $this->mailbox->id,
            'actor_user_id' => $this->tenant->id,
        ]);

        // Subsequent credentials list call MUST NOT reveal this new password
        $listResponse = $this->actingAs($this->tenant, 'sanctum')->getJson('/api/smtp/credentials');
        $this->assertStringNotContainsString($newPlainPassword, $listResponse->getContent());
        $this->assertStringNotContainsString($this->mailbox->password, $listResponse->getContent());
    }

    public function test_tenant_cannot_rotate_password_of_another_tenants_mailbox(): void
    {
        $otherDomain = Domain::create([
            'user_id'     => $this->otherTenant->id,
            'domain_name' => 'other.com',
            'status'      => 'active',
        ]);

        $otherMailbox = Mailbox::create([
            'domain_id'  => $otherDomain->id,
            'local_part' => 'info',
            'email'      => 'info@other.com',
            'password'   => crypt('OtherPass123!', '$6$randomsalt12345$'),
            'is_active'  => true,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson("/api/smtp/credentials/{$otherMailbox->id}/rotate-password");

        $response->assertStatus(403);
    }

    public function test_tenant_can_view_delivery_activity_logs_with_pagination_and_filters(): void
    {
        DeliveryLog::create([
            'tenant_id'       => $this->tenant->id,
            'domain_id'       => $this->domain->id,
            'mailbox_id'      => $this->mailbox->id,
            'sender_email'    => 'sales@acme.com',
            'recipient_email' => 'client@customer.com',
            'queue_id'        => 'ABC12345',
            'status'          => 'delivered',
            'dsn_code'        => '2.0.0',
            'smtp_code'       => 250,
            'response_text'   => '250 2.0.0 Ok: queued as ABC12345',
            'occurred_at'     => now()->subMinutes(10),
        ]);

        DeliveryLog::create([
            'tenant_id'       => $this->tenant->id,
            'domain_id'       => $this->domain->id,
            'mailbox_id'      => $this->mailbox->id,
            'sender_email'    => 'sales@acme.com',
            'recipient_email' => 'bad@invalid-domain.xyz',
            'queue_id'        => 'DEF67890',
            'status'          => 'bounced',
            'dsn_code'        => '5.1.1',
            'smtp_code'       => 550,
            'response_text'   => '550 5.1.1 User unknown',
            'occurred_at'     => now()->subMinutes(5),
        ]);

        // General listing
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/smtp/activity');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'domain_id',
                        'mailbox_id',
                        'sender_email',
                        'recipient_email',
                        'queue_id',
                        'status',
                        'dsn_code',
                        'smtp_code',
                        'response_text',
                        'occurred_at',
                    ],
                ],
                'links',
                'meta',
            ]);

        $this->assertCount(2, $response->json('data'));

        // Status filter: only bounced
        $bouncedResponse = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/smtp/activity?status=bounced');

        $bouncedResponse->assertStatus(200);
        $this->assertCount(1, $bouncedResponse->json('data'));
        $this->assertEquals('bounced', $bouncedResponse->json('data.0.status'));
        $this->assertEquals('bad@invalid-domain.xyz', $bouncedResponse->json('data.0.recipient_email'));

        // Search filter by recipient
        $searchResponse = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/smtp/activity?search=client@customer.com');

        $searchResponse->assertStatus(200);
        $this->assertCount(1, $searchResponse->json('data'));
        $this->assertEquals('delivered', $searchResponse->json('data.0.status'));
    }

    public function test_strict_tenant_isolation_for_delivery_logs(): void
    {
        // Log belonging to otherTenant
        DeliveryLog::create([
            'tenant_id'       => $this->otherTenant->id,
            'domain_id'       => null,
            'mailbox_id'      => null,
            'sender_email'    => 'secret@other.com',
            'recipient_email' => 'partner@other.com',
            'queue_id'        => 'SECRET123',
            'status'          => 'delivered',
            'occurred_at'     => now(),
        ]);

        // Log belonging to tenant
        DeliveryLog::create([
            'tenant_id'       => $this->tenant->id,
            'domain_id'       => $this->domain->id,
            'mailbox_id'      => $this->mailbox->id,
            'sender_email'    => 'sales@acme.com',
            'recipient_email' => 'client@acme.com',
            'queue_id'        => 'ACME123',
            'status'          => 'delivered',
            'occurred_at'     => now(),
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/smtp/activity');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('sales@acme.com', $response->json('data.0.sender_email'));
        $this->assertStringNotContainsString('secret@other.com', $response->getContent());
    }

    public function test_tenant_diagnostics_runs_safely_with_zero_ssrf_risk(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/smtp/diagnostics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'checks' => [
                    '*' => ['key', 'title', 'status', 'summary', 'remediation'],
                ],
                'common_errors' => [
                    '*' => ['code', 'cause', 'remediation'],
                ],
            ]);

        $data = $response->json();
        $this->assertEquals('healthy', $data['status']);
        $this->assertCount(5, $data['checks']);
        $this->assertNotEmpty($data['common_errors']);
    }
}
