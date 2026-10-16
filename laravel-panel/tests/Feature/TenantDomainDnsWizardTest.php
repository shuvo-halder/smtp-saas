<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\User;
use App\Services\DnsVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDomainDnsWizardTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected User $otherTenant;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                            => 'Business Pro',
            'slug'                            => 'business-pro',
            'max_domains'                     => 5,
            'max_mailboxes_per_domain'        => 20,
            'storage_mb_per_mailbox'          => 1024,
            'max_aliases_per_domain'          => 10,
            'daily_outbound_recipients'       => 1000,
            'mailbox_daily_outbound_recipients'=> 200,
            'price_monthly'                   => 49.00,
            'price_yearly'                    => 490.00,
            'is_active'                       => true,
            'features'                        => ['Custom Domains', 'DKIM & SPF', 'DMARC'],
        ]);

        $this->tenant = User::factory()->create([
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => Carbon::now()->addMonth(),
            'status'          => 'active',
            'is_admin'        => false,
        ]);

        $this->otherTenant = User::factory()->create([
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => Carbon::now()->addMonth(),
            'status'          => 'active',
            'is_admin'        => false,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_dns_wizard(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'wizard-test.com',
            'status'      => 'pending',
        ]);

        $this->getJson("/api/domains/{$domain->id}/dns-wizard")
            ->assertStatus(401);

        $this->postJson("/api/domains/{$domain->id}/verify-dns")
            ->assertStatus(401);
    }

    public function test_cross_tenant_cannot_access_another_tenants_dns_wizard(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'wizard-test.com',
            'status'      => 'pending',
        ]);

        $this->actingAs($this->otherTenant)
            ->getJson("/api/domains/{$domain->id}/dns-wizard")
            ->assertStatus(403);

        $this->actingAs($this->otherTenant)
            ->postJson("/api/domains/{$domain->id}/verify-dns")
            ->assertStatus(403);
    }

    public function test_tenant_with_inactive_subscription_cannot_access_dns_wizard(): void
    {
        $this->tenant->update([
            'plan_expires_at' => Carbon::now()->subDay(),
        ]);

        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'expired-test.com',
            'status'      => 'pending',
        ]);

        $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$domain->id}/dns-wizard")
            ->assertStatus(403);

        $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/verify-dns")
            ->assertStatus(403);
    }

    public function test_dns_wizard_returns_complete_data_structure(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'structure-test.com',
            'status'      => 'pending',
        ]);

        // Provide mock resolver returning missing records
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: fn($host, $type) => []
        );
        $this->app->instance(DnsVerificationService::class, $dnsService);

        $response = $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$domain->id}/dns-wizard");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'domain' => [
                        'id',
                        'domain_name',
                        'status',
                        'is_verified',
                        'activated_at',
                        'last_dns_check_at',
                    ],
                    'deliverability_score' => [
                        'score_percent',
                        'status',
                        'status_label',
                        'summary',
                    ],
                    'records' => [
                        '*' => [
                            'id',
                            'type',
                            'host',
                            'value',
                            'expected_value',
                            'priority',
                            'purpose',
                            'purpose_description',
                            'status',
                            'verified',
                            'actual_value',
                            'is_required',
                            'last_checked_at',
                            'troubleshooting',
                        ],
                    ],
                    'inbound_routing' => [
                        'status',
                        'mx_target',
                        'mx_priority',
                        'details',
                    ],
                    'outbound_authentication' => [
                        'spf_status',
                        'dkim_status',
                        'dmarc_status',
                        'spf_policy',
                        'dmarc_policy',
                        'details',
                    ],
                    'infrastructure_status' => [
                        'dns_publication',
                        'application_config',
                        'mail_server_mta',
                        'mta_driver',
                        'mta_note',
                    ],
                    'recommendations',
                ],
            ]);

        $this->assertEquals(0, $response->json('data.deliverability_score.score_percent'));
        $this->assertEquals('critical', $response->json('data.deliverability_score.status'));
    }

    public function test_mx_verification_matching_server(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_MX) {
                    return [
                        ['target' => 'mail.mailsaas.com.', 'pri' => 10],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyMx('company.com');

        $this->assertTrue($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_VERIFIED, $result['status']);
        $this->assertNull($result['troubleshooting']);
    }

    public function test_mx_verification_missing_records(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: fn($host, $type) => []
        );

        $result = $dnsService->verifyMx('company.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISSING, $result['status']);
        $this->assertStringContainsString('No MX records found', $result['troubleshooting']);
    }

    public function test_mx_verification_mismatched_target(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_MX) {
                    return [
                        ['target' => 'smtp.google.com.', 'pri' => 10],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyMx('company.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISMATCH, $result['status']);
        $this->assertStringContainsString('point to [10 smtp.google.com] instead', $result['troubleshooting']);
    }

    public function test_mx_verification_multiple_records_with_priority_warning(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_MX) {
                    return [
                        ['target' => 'backup-mx.external.com.', 'pri' => 5],
                        ['target' => 'mail.mailsaas.com.', 'pri' => 10],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyMx('company.com');

        // Verified because expected host is present, but troubleshooting notes priority conflict
        $this->assertTrue($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_VERIFIED, $result['status']);
        $this->assertStringContainsString('another MX record has priority 5', $result['troubleshooting']);
    }

    public function test_spf_verification_valid_matching_server(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT) {
                    return [
                        ['txt' => 'v=spf1 mx a:mail.mailsaas.com ~all'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifySpf('company.com');

        $this->assertTrue($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_VERIFIED, $result['status']);
        $this->assertFalse($result['has_duplicate']);
    }

    public function test_spf_verification_missing(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: fn($host, $type) => []
        );

        $result = $dnsService->verifySpf('company.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISSING, $result['status']);
        $this->assertStringContainsString('No SPF TXT record found', $result['troubleshooting']);
    }

    public function test_spf_verification_duplicate_records_rfc7208_violation(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT) {
                    return [
                        ['txt' => 'v=spf1 include:_spf.google.com ~all'],
                        ['txt' => 'v=spf1 mx a:mail.mailsaas.com ~all'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifySpf('company.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISMATCH, $result['status']);
        $this->assertTrue($result['has_duplicate']);
        $this->assertStringContainsString('Multiple SPF records detected', $result['troubleshooting']);
        $this->assertStringContainsString('RFC 7208', $result['troubleshooting']);
    }

    public function test_spf_verification_mismatched_server(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT) {
                    return [
                        ['txt' => 'v=spf1 include:someothermail.com -all'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifySpf('company.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISMATCH, $result['status']);
        $this->assertStringContainsString('does not authorize', $result['troubleshooting']);
    }

    public function test_dkim_verification_not_configured_when_key_is_missing(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: fn($host, $type) => []
        );

        $result = $dnsService->verifyDkim('company.com', 'default', null);

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_NOT_CONFIGURED, $result['status']);
        $this->assertStringContainsString('DKIM key pair has not been generated', $result['troubleshooting']);
    }

    public function test_dkim_verification_valid_matching_public_key(): void
    {
        $rawKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA09876543210ABCDEF...AQAB';
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) use ($rawKey) {
                if ($type === DNS_TXT && str_contains($host, 'default._domainkey')) {
                    return [
                        ['txt' => "v=DKIM1; k=rsa; p={$rawKey}"],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyDkim('company.com', 'default', $rawKey);

        $this->assertTrue($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_VERIFIED, $result['status']);
        $this->assertNull($result['troubleshooting']);
    }

    public function test_dkim_verification_multi_chunk_txt_concatenation(): void
    {
        $chunk1 = 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0123456789ABCDEF0123456789ABCDEF';
        $chunk2 = '112233445566778899AABBCCDDEEFF00112233445566778899AABBCCDDEEFF00AQAB';
        $fullKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0123456789ABCDEF0123456789ABCDEF112233445566778899AABBCCDDEEFF00112233445566778899AABBCCDDEEFF00AQAB';

        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) use ($chunk1, $chunk2) {
                if ($type === DNS_TXT && str_contains($host, 'default._domainkey')) {
                    return [
                        [
                            'entries' => [$chunk1, $chunk2],
                            'txt'     => $chunk1, // simulate PHP returning only first chunk in txt field
                        ],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyDkim('company.com', 'default', $fullKey);

        $this->assertTrue($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_VERIFIED, $result['status']);
        $this->assertNull($result['troubleshooting']);
    }

    public function test_dkim_verification_mismatched_public_key(): void
    {
        $serverKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA_SERVER_KEY...AQAB';
        $dnsKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA_DIFFERENT_KEY...AQAB';

        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) use ($dnsKey) {
                if ($type === DNS_TXT && str_contains($host, 'default._domainkey')) {
                    return [
                        ['txt' => "v=DKIM1; k=rsa; p={$dnsKey}"],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyDkim('company.com', 'default', $serverKey);

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISMATCH, $result['status']);
        $this->assertStringContainsString('does not match this server\'s public key', $result['troubleshooting']);
    }

    public function test_dkim_verification_missing(): void
    {
        $serverKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA_SERVER_KEY...AQAB';

        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: fn($host, $type) => []
        );

        $result = $dnsService->verifyDkim('company.com', 'default', $serverKey);

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISSING, $result['status']);
        $this->assertStringContainsString('No DKIM TXT record found', $result['troubleshooting']);
    }

    public function test_dmarc_verification_valid(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT && str_contains($host, '_dmarc')) {
                    return [
                        ['txt' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@company.com'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyDmarc('company.com');

        $this->assertTrue($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_VERIFIED, $result['status']);
        $this->assertEquals('quarantine', $result['policy']);
        $this->assertNull($result['troubleshooting']);
    }

    public function test_dmarc_verification_missing(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: fn($host, $type) => []
        );

        $result = $dnsService->verifyDmarc('company.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISSING, $result['status']);
        $this->assertStringContainsString('No DMARC record found', $result['troubleshooting']);
    }

    public function test_dmarc_verification_syntax_error(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT && str_contains($host, '_dmarc')) {
                    return [
                        ['txt' => 'invalid-dmarc-record-without-prefix'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyDmarc('company.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISMATCH, $result['status']);
        $this->assertStringContainsString('does not start with \'v=DMARC1\'', $result['troubleshooting']);
    }

    public function test_resolver_timeout_or_failure_reports_check_failed(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: fn($host, $type) => false // Simulate resolver failure / timeout
        );

        $mx = $dnsService->verifyMx('company.com');
        $spf = $dnsService->verifySpf('company.com');
        $dkim = $dnsService->verifyDkim('company.com', 'default', 'some_key');
        $dmarc = $dnsService->verifyDmarc('company.com');

        $this->assertFalse($mx['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_CHECK_FAILED, $mx['status']);

        $this->assertFalse($spf['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_CHECK_FAILED, $spf['status']);

        $this->assertFalse($dkim['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_CHECK_FAILED, $dkim['status']);

        $this->assertFalse($dmarc['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_CHECK_FAILED, $dmarc['status']);
    }

    public function test_domain_activates_when_mx_spf_dkim_are_all_verified(): void
    {
        $rawKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA1234567890ABCDEF...AQAB';

        $domain = Domain::create([
            'user_id'         => $this->tenant->id,
            'domain_name'     => 'activation-test.com',
            'status'          => 'pending',
            'dkim_selector'   => 'default',
            'dkim_public_key' => $rawKey,
        ]);

        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) use ($rawKey) {
                if ($type === DNS_MX) {
                    return [['target' => 'mail.mailsaas.com.', 'pri' => 10]];
                }
                if ($type === DNS_TXT && str_contains($host, 'default._domainkey')) {
                    return [['txt' => "v=DKIM1; k=rsa; p={$rawKey}"]];
                }
                if ($type === DNS_TXT && str_contains($host, '_dmarc')) {
                    return [['txt' => 'v=DMARC1; p=quarantine;']];
                }
                if ($type === DNS_TXT) {
                    return [['txt' => 'v=spf1 mx a:mail.mailsaas.com ~all']];
                }
                return [];
            }
        );
        $this->app->instance(DnsVerificationService::class, $dnsService);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/verify-dns");

        $response->assertStatus(200)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('domain.status', 'active');

        $this->assertDatabaseHas('domains', [
            'id'             => $domain->id,
            'status'         => 'active',
            'mx_verified'    => 1,
            'spf_verified'   => 1,
            'dkim_verified'  => 1,
            'dmarc_verified' => 1,
        ]);

        $this->assertNotNull($domain->fresh()->activated_at);
        $this->assertNotNull($domain->fresh()->last_dns_check_at);
        $this->assertEquals(100, $response->json('wizard.deliverability_score.score_percent'));
        $this->assertEquals('ready', $response->json('wizard.deliverability_score.status'));
    }

    public function test_verify_dns_records_audit_log_without_sensitive_material(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'audit-test.com',
            'status'      => 'pending',
        ]);

        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: fn($host, $type) => []
        );
        $this->app->instance(DnsVerificationService::class, $dnsService);

        $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/verify-dns")
            ->assertStatus(200);

        $log = AuditLog::where('action', 'domain.dns_verify')
            ->where('entity_id', $domain->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Domain', $log->entity_type);
        $this->assertEquals($this->tenant->id, $log->actor_user_id);
        $this->assertArrayNotHasKey('password', $log->after_state ?? []);
        $this->assertArrayNotHasKey('private_key', $log->after_state ?? []);
    }

    public function test_backwards_compatible_verify_endpoint_still_works(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'compat-test.com',
            'status'      => 'pending',
        ]);

        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: fn($host, $type) => []
        );
        $this->app->instance(DnsVerificationService::class, $dnsService);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$domain->id}/verify");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'verified',
                'message',
                'results' => [
                    'mx',
                    'spf',
                    'dkim',
                    'dmarc',
                ],
                'domain',
            ])
            ->assertJsonPath('verified', false)
            ->assertJsonPath('domain.status', 'pending');
    }
}
