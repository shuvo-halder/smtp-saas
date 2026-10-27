<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\User;
use App\Services\DmarcReportParserService;
use App\Services\DnsVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TenantDomainDeliverabilityReportTest extends TestCase
{
    use RefreshDatabase;

    private User $tenant;
    private User $otherTenant;
    private Plan $plan;
    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                            => 'Professional',
            'slug'                            => 'pro',
            'price_monthly'                   => 29.00,
            'price_yearly'                    => 290.00,
            'max_domains'                     => 5,
            'max_mailboxes_per_domain'        => 25,
            'storage_mb_per_mailbox'          => 2048,
            'max_aliases_per_domain'          => 50,
            'daily_outbound_recipients'       => 1000,
            'mailbox_daily_outbound_recipients'=> 200,
            'features'                        => ['Custom Domains', 'DKIM & SPF', 'DMARC', 'Deliverability Reports'],
        ]);

        $this->tenant = User::create([
            'name'            => 'Acme Tenant',
            'email'           => 'admin@acme.com',
            'password'        => bcrypt('Secret123!'),
            'status'          => 'active',
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        $this->otherTenant = User::create([
            'name'            => 'Other Tenant',
            'email'           => 'other@example.com',
            'password'        => bcrypt('Secret123!'),
            'status'          => 'active',
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        $this->domain = Domain::create([
            'user_id'         => $this->tenant->id,
            'domain_name'     => 'acme.com',
            'status'          => 'active',
            'mx_verified'     => true,
            'spf_verified'    => true,
            'dkim_verified'   => true,
            'dmarc_verified'  => true,
            'dkim_selector'   => 'default',
            'dkim_public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA_VALID_KEY_AQAB',
            'activated_at'    => now(),
        ]);
    }

    // ─── Authorization & Tenant Boundary ───────────────────────────────────────

    public function test_tenant_can_view_deliverability_report_for_owned_domain(): void
    {
        $response = $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$this->domain->id}/deliverability-report");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'domain' => [
                        'id', 'domain_name', 'status', 'is_verified', 'dns_drift_detected',
                    ],
                    'deliverability_score' => [
                        'score_percent', 'status', 'status_label', 'breakdown', 'summary', 'disclaimer',
                    ],
                    'inbound_routing',
                    'outbound_authentication',
                    'infrastructure_status',
                    'recommendations',
                    'dmarc_advisor' => [
                        'current_policy', 'policies', 'policy_escalation_rule',
                    ],
                    'verification_history',
                ],
            ]);
    }

    public function test_cross_tenant_cannot_view_deliverability_report(): void
    {
        $response = $this->actingAs($this->otherTenant)
            ->getJson("/api/domains/{$this->domain->id}/deliverability-report");

        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_deliverability_report(): void
    {
        $response = $this->getJson("/api/domains/{$this->domain->id}/deliverability-report");

        $response->assertStatus(401);
    }

    public function test_suspended_tenant_is_blocked_from_deliverability_report(): void
    {
        $this->tenant->update(['status' => 'suspended']);

        $response = $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$this->domain->id}/deliverability-report");

        $response->assertStatus(403);
    }

    public function test_expired_subscription_is_blocked_from_deliverability_report(): void
    {
        $this->tenant->update(['plan_expires_at' => now()->subDay()]);

        $response = $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$this->domain->id}/deliverability-report");

        $response->assertStatus(403);
    }

    // ─── Deliverability Score & Limitations Disclaimer ─────────────────────────

    public function test_deliverability_report_includes_documented_score_and_limitations_disclaimer(): void
    {
        $response = $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$this->domain->id}/deliverability-report");

        $response->assertStatus(200);

        $scoreData = $response->json('data.deliverability_score');
        $this->assertArrayHasKey('disclaimer', $scoreData);
        $this->assertStringContainsString('DNS verification confirms publication', $scoreData['disclaimer']);
        $this->assertStringContainsString('It does not evaluate live message delivery', $scoreData['disclaimer']);

        // Check weighted breakdown
        $breakdown = $scoreData['breakdown'];
        $this->assertEquals(40, $breakdown['mx']['weight']);
        $this->assertEquals(25, $breakdown['spf']['weight']);
        $this->assertEquals(25, $breakdown['dkim']['weight']);
        $this->assertEquals(10, $breakdown['dmarc']['weight']);
    }

    // ─── SPF Hardening & Syntax Diagnostics ────────────────────────────────────

    public function test_spf_detection_multiple_records_violates_rfc7208(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT && $host === 'acme.com') {
                    return [
                        ['txt' => 'v=spf1 mx ~all'],
                        ['txt' => 'v=spf1 ip4:1.2.3.4 ~all'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifySpf('acme.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISMATCH, $result['status']);
        $this->assertTrue($result['has_duplicate']);
        $this->assertStringContainsString('RFC 7208', $result['troubleshooting']);
    }

    public function test_spf_syntax_detects_insecure_permissive_all_warning(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT && $host === 'acme.com') {
                    return [
                        ['txt' => 'v=spf1 a:mail.mailsaas.com +all'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifySpf('acme.com');

        $this->assertTrue($result['verified']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('+all', $result['warnings'][0]);
    }

    public function test_spf_syntax_detects_malformed_mechanism(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT && $host === 'acme.com') {
                    return [
                        ['txt' => 'v=spf1 ip4:999.999.999.999 ~all'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifySpf('acme.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISMATCH, $result['status']);
        $this->assertFalse($result['syntax_valid']);
        $this->assertStringContainsString('invalid IPv4', $result['troubleshooting']);
    }

    public function test_spf_syntax_detects_excessive_dns_lookups_warning(): void
    {
        // 11 lookup mechanisms
        $mechanisms = 'v=spf1 a mx include:s1.com include:s2.com include:s3.com include:s4.com include:s5.com include:s6.com include:s7.com include:s8.com include:s9.com ~all';
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) use ($mechanisms) {
                if ($type === DNS_TXT && $host === 'acme.com') {
                    return [['txt' => $mechanisms]];
                }
                return [];
            }
        );

        $result = $dnsService->verifySpf('acme.com');

        $this->assertTrue($result['syntax_valid']);
        $warningsStr = implode(' ', $result['warnings']);
        $this->assertStringContainsString('RFC 7208 limit of 10 lookups', $warningsStr);
    }

    // ─── DKIM Hardening & Revocation Diagnostics ───────────────────────────────

    public function test_dkim_detects_revoked_key_with_empty_p_tag(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT && str_contains($host, 'default._domainkey')) {
                    return [['txt' => 'v=DKIM1; k=rsa; p=;']];
                }
                return [];
            }
        );

        $result = $dnsService->verifyDkim('acme.com', 'default', 'SOME_KEY');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISMATCH, $result['status']);
        $this->assertTrue($result['is_revoked'] ?? false);
        $this->assertStringContainsString('revocation', $result['troubleshooting']);
    }

    public function test_dkim_warns_on_short_key_length(): void
    {
        // ~1024-bit key base64 (about 160 chars)
        $shortKey = str_repeat('A', 160);
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) use ($shortKey) {
                if ($type === DNS_TXT && str_contains($host, 'default._domainkey')) {
                    return [['txt' => "v=DKIM1; k=rsa; p={$shortKey}"]];
                }
                return [];
            }
        );

        $result = $dnsService->verifyDkim('acme.com', 'default', $shortKey);

        $this->assertTrue($result['verified']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('2048-bit', $result['warnings'][0]);
    }

    // ─── DMARC Hardening & Policy Explanations ─────────────────────────────────

    public function test_dmarc_detects_duplicate_records_violates_rfc7489(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT && str_contains($host, '_dmarc')) {
                    return [
                        ['txt' => 'v=DMARC1; p=none;'],
                        ['txt' => 'v=DMARC1; p=quarantine;'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyDmarc('acme.com');

        $this->assertFalse($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_MISMATCH, $result['status']);
        $this->assertTrue($result['has_duplicate']);
        $this->assertStringContainsString('RFC 7489', $result['explanation']);
    }

    public function test_dmarc_parses_all_policies_and_optional_tags(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_TXT && str_contains($host, '_dmarc')) {
                    return [
                        ['txt' => 'v=DMARC1; p=reject; sp=quarantine; pct=80; rua=mailto:dmarc@acme.com; ruf=mailto:forensics@acme.com; adkim=s; aspf=s;'],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyDmarc('acme.com');

        $this->assertTrue($result['verified']);
        $this->assertEquals(DnsVerificationService::STATUS_VERIFIED, $result['status']);
        $this->assertEquals('reject', $result['policy']);
        $this->assertEquals('quarantine', $result['subdomain_policy']);
        $this->assertEquals(80, $result['pct']);
        $this->assertEquals('mailto:dmarc@acme.com', $result['rua']);
        $this->assertEquals('mailto:forensics@acme.com', $result['ruf']);
        $this->assertEquals('s', $result['adkim']);
        $this->assertEquals('s', $result['aspf']);
        $this->assertStringContainsString('Full Enforcement Policy', $result['explanation']);
    }

    public function test_dmarc_advisor_explains_none_quarantine_reject_and_escalation_rule(): void
    {
        $response = $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$this->domain->id}/deliverability-report");

        $response->assertStatus(200);

        $advisor = $response->json('data.dmarc_advisor');
        $this->assertNotNull($advisor);
        $this->assertCount(3, $advisor['policies']);
        $this->assertStringContainsString('never automatically escalates', $advisor['policy_escalation_rule']);

        $policies = collect($advisor['policies'])->keyBy('policy');
        $this->assertStringContainsString('Monitoring Mode', $policies['none']['name']);
        $this->assertStringContainsString('Spam Quarantine Mode', $policies['quarantine']['name']);
        $this->assertStringContainsString('Strict Block Mode', $policies['reject']['name']);
    }

    // ─── MX Hardening & Prohibitions ───────────────────────────────────────────

    public function test_mx_warns_when_target_points_to_bare_ip_address(): void
    {
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                if ($type === DNS_MX) {
                    return [
                        ['target' => '192.168.1.1.', 'pri' => 10],
                    ];
                }
                return [];
            }
        );

        $result = $dnsService->verifyMx('acme.com');

        $this->assertFalse($result['verified']);
        $warnings = implode(' ', $result['warnings']);
        $this->assertStringContainsString('RFC 5321 section 5.1', $warnings);
    }

    // ─── DNS Drift Detection ───────────────────────────────────────────────────

    public function test_dns_drift_detected_when_active_domain_loses_dns_records(): void
    {
        // Domain is active, but DNS query returns empty
        $dnsService = new DnsVerificationService(
            mailServerHost: 'mail.mailsaas.com',
            resolver: function ($host, $type) {
                return [];
            }
        );

        $wizardData = $dnsService->getDnsWizardData($this->domain, refresh: true);

        $this->assertTrue($wizardData['domain']['dns_drift_detected']);
    }

    // ─── Verification History Integration ──────────────────────────────────────

    public function test_verification_history_is_extracted_from_audit_logs(): void
    {
        // Seed 2 verification audit log entries
        AuditLog::create([
            'actor_user_id' => $this->tenant->id,
            'actor_email'   => $this->tenant->email,
            'action'        => 'domain.dns_verify',
            'entity_type'   => 'Domain',
            'entity_id'     => $this->domain->id,
            'before_state'  => ['status' => 'pending'],
            'after_state'   => [
                'status'             => 'active',
                'score'              => 100,
                'mx_status'          => 'VERIFIED',
                'spf_status'         => 'VERIFIED',
                'dkim_status'        => 'VERIFIED',
                'dmarc_status'       => 'VERIFIED',
                'dns_drift_detected' => false,
            ],
            'reason'        => 'Verification test 1',
            'created_at'    => now()->subHours(2),
        ]);

        AuditLog::create([
            'actor_user_id' => $this->tenant->id,
            'actor_email'   => $this->tenant->email,
            'action'        => 'domain.dns_verify',
            'entity_type'   => 'Domain',
            'entity_id'     => $this->domain->id,
            'before_state'  => ['status' => 'active'],
            'after_state'   => [
                'status'             => 'active',
                'score'              => 90,
                'mx_status'          => 'VERIFIED',
                'spf_status'         => 'VERIFIED',
                'dkim_status'        => 'VERIFIED',
                'dmarc_status'       => 'MISSING',
                'dns_drift_detected' => false,
            ],
            'reason'        => 'Verification test 2',
            'created_at'    => now()->subHour(),
        ]);

        $response = $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$this->domain->id}/deliverability-report");

        $response->assertStatus(200);

        $history = $response->json('data.verification_history');
        $this->assertCount(2, $history);
        $this->assertEquals(90, $history[0]['score']);
        $this->assertEquals(100, $history[1]['score']);
    }

    // ─── DMARC Aggregate Report Parser Foundation ──────────────────────────────

    public function test_dmarc_report_parser_service_parses_valid_xml(): void
    {
        $sampleXml = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<feedback>
  <report_metadata>
    <org_name>google.com</org_name>
    <email>noreply-dmarc-support@google.com</email>
    <extra_contact_info>https://support.google.com/a/answer/2466580</extra_contact_info>
    <report_id>1234567890123456789</report_id>
    <date_range>
      <begin>1700000000</begin>
      <end>1700086400</end>
    </date_range>
  </report_metadata>
  <policy_published>
    <domain>acme.com</domain>
    <adkim>r</adkim>
    <aspf>r</aspf>
    <p>quarantine</p>
    <sp>quarantine</sp>
    <pct>100</pct>
  </policy_published>
  <record>
    <row>
      <source_ip>192.0.2.1</source_ip>
      <count>15</count>
      <policy_evaluated>
        <disposition>none</disposition>
        <dkim>pass</dkim>
        <spf>pass</spf>
      </policy_evaluated>
    </row>
    <identifiers>
      <header_from>acme.com</header_from>
    </identifiers>
    <auth_results>
      <dkim>
        <domain>acme.com</domain>
        <result>pass</result>
        <selector>default</selector>
      </dkim>
      <spf>
        <domain>acme.com</domain>
        <scope>mfrom</scope>
        <result>pass</result>
      </spf>
    </auth_results>
  </record>
</feedback>
XML;

        $parser = new DmarcReportParserService();
        $parsed = $parser->parseXml($sampleXml);

        $this->assertEquals('google.com', $parsed['metadata']['org_name']);
        $this->assertEquals('acme.com', $parsed['policy_published']['domain']);
        $this->assertEquals(15, $parsed['summary']['total_messages']);
        $this->assertEquals(15, $parsed['summary']['total_passed']);
        $this->assertEquals(0, $parsed['summary']['total_failed']);
        $this->assertEquals(100.0, $parsed['summary']['pass_rate_percent']);
        $this->assertNotEmpty($parsed['digest']);
    }

    public function test_dmarc_report_parser_rejects_xxe_payload(): void
    {
        $xxePayload = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE feedback [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<feedback>
  <report_metadata>
    <org_name>&xxe;</org_name>
  </report_metadata>
</feedback>
XML;

        $parser = new DmarcReportParserService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('XXE protection');

        $parser->parseXml($xxePayload);
    }

    public function test_dmarc_report_parser_rejects_oversized_payload(): void
    {
        // 5MB + 1 byte
        $hugeContent = '<feedback>' . str_repeat(' ', (5 * 1024 * 1024) + 10) . '</feedback>';

        $parser = new DmarcReportParserService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum allowed size');

        $parser->parseXml($hugeContent);
    }

    public function test_dmarc_report_api_endpoint_parses_uploaded_file(): void
    {
        $sampleXml = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<feedback>
  <report_metadata>
    <org_name>microsoft.com</org_name>
    <email>dmarc@microsoft.com</email>
    <report_id>ms-report-987</report_id>
    <date_range>
      <begin>1700000000</begin>
      <end>1700086400</end>
    </date_range>
  </report_metadata>
  <policy_published>
    <domain>acme.com</domain>
    <p>quarantine</p>
  </policy_published>
  <record>
    <row>
      <source_ip>198.51.100.5</source_ip>
      <count>5</count>
      <policy_evaluated>
        <disposition>none</disposition>
        <dkim>pass</dkim>
        <spf>pass</spf>
      </policy_evaluated>
    </row>
  </record>
</feedback>
XML;

        $file = UploadedFile::fake()->createWithContent('dmarc-report.xml', $sampleXml);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$this->domain->id}/parse-dmarc-report", [
                'report_file' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.metadata.org_name', 'microsoft.com')
            ->assertJsonPath('data.summary.total_messages', 5)
            ->assertJsonPath('data.summary.total_passed', 5);
    }

    public function test_dmarc_report_api_rejects_domain_mismatch(): void
    {
        $mismatchedXml = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<feedback>
  <report_metadata>
    <org_name>google.com</org_name>
    <report_id>test-123</report_id>
    <date_range><begin>1700000000</begin><end>1700086400</end></date_range>
  </report_metadata>
  <policy_published>
    <domain>otherdomain.com</domain>
    <p>none</p>
  </policy_published>
</feedback>
XML;

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/domains/{$this->domain->id}/parse-dmarc-report", [
                'xml_content' => $mismatchedXml,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', "DMARC report domain 'otherdomain.com' does not match domain 'acme.com'.");
    }

    // ─── Secret Redaction Test ─────────────────────────────────────────────────

    public function test_private_keys_never_appear_in_deliverability_report(): void
    {
        $response = $this->actingAs($this->tenant)
            ->getJson("/api/domains/{$this->domain->id}/deliverability-report");

        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringNotContainsString('BEGIN RSA PRIVATE KEY', $content);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $content);
        $this->assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY', $content);
    }
}
