<?php

namespace Tests\Feature;

use App\Models\AbuseIncident;
use App\Models\DeliveryLog;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\DnsVerificationService;
use App\Services\TenantSmtpService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class TenantSecurityDeliverabilityDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected User $otherTenant;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                             => 'Professional',
            'slug'                             => 'professional',
            'max_domains'                      => 5,
            'max_mailboxes_per_domain'         => 10,
            'storage_mb_per_mailbox'           => 1024,
            'max_aliases_per_domain'           => 20,
            'daily_outbound_recipients'        => 500,
            'mailbox_daily_outbound_recipients'=> 100,
            'price_monthly'                    => 29.99,
            'price_yearly'                     => 299.99,
            'is_active'                        => true,
            'features'                         => ['custom_domains', 'dns_wizard', 'dmarc_parser'],
        ]);

        $this->tenant = User::factory()->create([
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->addMonth(),
        ]);

        $this->otherTenant = User::factory()->create([
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/dashboard');
        $response->assertStatus(401);
    }

    public function test_user_without_active_subscription_cannot_access_dashboard(): void
    {
        $expiredTenant = User::factory()->create([
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($expiredTenant)->getJson('/api/dashboard');
        $response->assertStatus(403);
    }

    public function test_dashboard_returns_complete_structure_with_per_section_statuses(): void
    {
        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'user' => [
                'id',
                'name',
                'email',
                'status',
                'plan',
                'plan_expires_at',
            ],
            'domain_count',
            'domains_count',
            'mailbox_count',
            'mailboxes_count',
            'domain_security' => [
                'status',
                'error',
                'summary' => [
                    'total_domains',
                    'active_domains',
                    'pending_domains',
                    'fully_authenticated_domains',
                    'domains_with_drift',
                    'average_deliverability_score',
                ],
                'domains',
                'recommendations',
            ],
            'smtp_usage' => [
                'status',
                'error',
                'data_source',
                'used_today',
                'daily_limit',
                'remaining_today',
                'percent_used',
                'is_unlimited',
                'quota_status',
                'quota_warning',
                'history',
                'delivery_summary' => [
                    'timeframe',
                    'total_logged',
                    'delivered',
                    'deferred',
                    'bounced',
                    'rejected',
                ],
            ],
            'recent_activity' => [
                'status',
                'error',
                'events',
            ],
            'abuse_and_security' => [
                'status',
                'error',
                'open_incidents_count',
                'incidents',
                'security_warnings',
            ],
            'dmarc_reporting' => [
                'status',
                'parser_available',
                'parser_format',
                'automated_collection',
                'automated_collection_status',
                'automated_collection_note',
            ],
            'recent_domains',
            'recent_invoices',
        ]);
    }

    public function test_dashboard_domain_security_calculates_scores_without_live_dns_queries(): void
    {
        // 1 active domain fully verified (Score: 100)
        Domain::create([
            'user_id'           => $this->tenant->id,
            'domain_name'       => 'auth-domain.com',
            'status'            => 'active',
            'mx_verified'       => true,
            'spf_verified'      => true,
            'dkim_verified'     => true,
            'dmarc_verified'    => true,
            'last_dns_check_at' => now(),
        ]);

        // 1 pending domain partially verified (MX=true, SPF=true, DKIM=false, DMARC=false -> Score: 65)
        Domain::create([
            'user_id'           => $this->tenant->id,
            'domain_name'       => 'partial-domain.com',
            'status'            => 'pending',
            'mx_verified'       => true,
            'spf_verified'      => true,
            'dkim_verified'     => false,
            'dmarc_verified'    => false,
            'last_dns_check_at' => now(),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $security = $response->json('domain_security');
        $this->assertEquals(2, $security['summary']['total_domains']);
        $this->assertEquals(1, $security['summary']['active_domains']);
        $this->assertEquals(1, $security['summary']['pending_domains']);
        $this->assertEquals(1, $security['summary']['fully_authenticated_domains']);
        $this->assertEquals(0, $security['summary']['domains_with_drift']);
        $this->assertEquals(83, $security['summary']['average_deliverability_score']); // (100 + 65) / 2 = 82.5 -> 83

        // Check per-domain items
        $domains = collect($security['domains']);
        $authDomain = $domains->firstWhere('domain_name', 'auth-domain.com');
        $this->assertEquals(100, $authDomain['score']);
        $this->assertEmpty($authDomain['missing_records']);

        $partialDomain = $domains->firstWhere('domain_name', 'partial-domain.com');
        $this->assertEquals(65, $partialDomain['score']);
        $this->assertContains('DKIM', $partialDomain['missing_records']);
        $this->assertContains('DMARC', $partialDomain['missing_records']);
    }

    public function test_dashboard_domain_security_detects_dns_drift_on_active_domain(): void
    {
        // Domain is active, but MX or SPF or DKIM is false -> DNS drift!
        Domain::create([
            'user_id'           => $this->tenant->id,
            'domain_name'       => 'drifted-domain.com',
            'status'            => 'active',
            'mx_verified'       => true,
            'spf_verified'      => false, // Record lost!
            'dkim_verified'     => true,
            'dmarc_verified'    => false,
            'last_dns_check_at' => now(),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $security = $response->json('domain_security');
        $this->assertEquals(1, $security['summary']['domains_with_drift']);
        $this->assertEquals('warning', $security['status']);

        $domainItem = $security['domains'][0];
        $this->assertTrue($domainItem['dns_drift_detected']);
        $this->assertContains('SPF', $domainItem['missing_records']);
    }

    public function test_dashboard_domain_security_handles_empty_domains_state(): void
    {
        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $security = $response->json('domain_security');
        $this->assertEquals('empty', $security['status']);
        $this->assertEquals(0, $security['summary']['total_domains']);
        $this->assertEmpty($security['domains']);
        $this->assertNotEmpty($security['recommendations']);
    }

    public function test_dashboard_smtp_usage_reports_quota_telemetry_and_historical_trend(): void
    {
        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $smtp = $response->json('smtp_usage');
        $this->assertEquals(500, $smtp['daily_limit']);
        $this->assertEquals('normal', $smtp['quota_status']);
        $this->assertFalse($smtp['is_unlimited']);
        $this->assertNotEmpty($smtp['history']);
        $this->assertCount(14, $smtp['history']);
    }

    public function test_dashboard_smtp_usage_reports_quota_near_limit_and_exhaustion_warnings(): void
    {
        // Mock TenantSmtpService to return 450 used out of 500 (90% >= 85%)
        $mockSmtp = Mockery::mock(TenantSmtpService::class);
        $mockSmtp->shouldReceive('getUsage')->andReturn([
            'plan_name'           => 'Professional',
            'daily_limit'         => 500,
            'is_unlimited'        => false,
            'used_today'          => 450,
            'remaining_today'     => 50,
            'percent_used'        => 90.0,
            'history'             => [],
            'mailboxes_breakdown' => [],
        ]);

        $this->app->instance(TenantSmtpService::class, $mockSmtp);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $smtp = $response->json('smtp_usage');
        $this->assertEquals('warning', $smtp['quota_status']);
        $this->assertNotNull($smtp['quota_warning']);
        $this->assertStringContainsString('near limit', $smtp['quota_warning']);
    }

    public function test_dashboard_smtp_usage_handles_service_degradation_gracefully(): void
    {
        // Mock TenantSmtpService throwing an unexpected exception (e.g. Redis connection refused)
        $mockSmtp = Mockery::mock(TenantSmtpService::class);
        $mockSmtp->shouldReceive('getUsage')->andThrow(new \RuntimeException('Redis connection timeout'));

        $this->app->instance(TenantSmtpService::class, $mockSmtp);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        // Dashboard overall succeeds, SMTP section is degraded, but domain section is operational!
        $smtp = $response->json('smtp_usage');
        $this->assertEquals('degraded', $smtp['status']);
        $this->assertNotNull($smtp['error']);
        $this->assertStringContainsString('unavailable', $smtp['error']);

        $domainSec = $response->json('domain_security');
        $this->assertNotEquals('error', $domainSec['status']);
    }

    public function test_dashboard_recent_activity_returns_tenants_own_delivery_logs_only(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'tenant-domain.com',
            'status'      => 'active',
        ]);

        $mailbox = Mailbox::create([
            'domain_id'  => $domain->id,
            'local_part' => 'sender',
            'email'      => 'sender@tenant-domain.com',
            'password'   => 'hash',
            'is_active'  => true,
            'quota_mb'   => 1024,
        ]);

        // Tenant's delivery log
        DeliveryLog::create([
            'tenant_id'       => $this->tenant->id,
            'domain_id'       => $domain->id,
            'mailbox_id'      => $mailbox->id,
            'sender_email'    => 'sender@tenant-domain.com',
            'recipient_email' => 'client@external.com',
            'queue_id'        => 'Q123456',
            'status'          => 'delivered',
            'dsn_code'        => '2.0.0',
            'smtp_code'       => 250,
            'response_text'   => '250 2.0.0 Ok: queued as Q123456',
            'occurred_at'     => now(),
        ]);

        // Other tenant's delivery log (must never appear!)
        DeliveryLog::create([
            'tenant_id'       => $this->otherTenant->id,
            'sender_email'    => 'other@other-domain.com',
            'recipient_email' => 'secret@target.com',
            'queue_id'        => 'Q999999',
            'status'          => 'delivered',
            'dsn_code'        => '2.0.0',
            'smtp_code'       => 250,
            'response_text'   => '250 Ok',
            'occurred_at'     => now(),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $events = $response->json('recent_activity.events');
        $this->assertCount(1, $events);
        $this->assertEquals('client@external.com', $events[0]['recipient_email']);
        $this->assertEquals('sender@tenant-domain.com', $events[0]['sender_email']);
    }

    public function test_dashboard_recent_activity_distinguishes_delivered_deferred_bounced_and_rejected_statuses(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'status-domain.com',
            'status'      => 'active',
        ]);

        DeliveryLog::create([
            'tenant_id'       => $this->tenant->id,
            'domain_id'       => $domain->id,
            'sender_email'    => 'user@status-domain.com',
            'recipient_email' => 'ok@domain.com',
            'queue_id'        => 'Q1',
            'status'          => 'delivered',
            'occurred_at'     => now()->subMinutes(10),
        ]);

        DeliveryLog::create([
            'tenant_id'       => $this->tenant->id,
            'domain_id'       => $domain->id,
            'sender_email'    => 'user@status-domain.com',
            'recipient_email' => 'retry@domain.com',
            'queue_id'        => 'Q2',
            'status'          => 'deferred',
            'occurred_at'     => now()->subMinutes(8),
        ]);

        DeliveryLog::create([
            'tenant_id'       => $this->tenant->id,
            'domain_id'       => $domain->id,
            'sender_email'    => 'user@status-domain.com',
            'recipient_email' => 'dead@domain.com',
            'queue_id'        => 'Q3',
            'status'          => 'bounced',
            'occurred_at'     => now()->subMinutes(5),
        ]);

        DeliveryLog::create([
            'tenant_id'       => $this->tenant->id,
            'domain_id'       => $domain->id,
            'sender_email'    => 'user@status-domain.com',
            'recipient_email' => 'denied@domain.com',
            'queue_id'        => 'Q4',
            'status'          => 'rejected',
            'occurred_at'     => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $summary = $response->json('smtp_usage.delivery_summary');
        $this->assertEquals(4, $summary['total_logged']);
        $this->assertEquals(1, $summary['delivered']);
        $this->assertEquals(1, $summary['deferred']);
        $this->assertEquals(1, $summary['bounced']);
        $this->assertEquals(1, $summary['rejected']);
    }

    public function test_dashboard_abuse_and_security_isolates_incidents_strictly_to_tenant(): void
    {
        // Tenant's incident
        AbuseIncident::create([
            'tenant_id'        => $this->tenant->id,
            'incident_type'    => 'consecutive_hard_bounces',
            'severity'         => 'high',
            'status'           => 'open',
            'detection_source' => 'log_parser',
            'summary'          => 'Mailbox reached 15 consecutive hard bounces.',
            'occurred_at'      => now(),
        ]);

        // Other tenant's incident
        AbuseIncident::create([
            'tenant_id'        => $this->otherTenant->id,
            'incident_type'    => 'daily_hard_bounce_threshold',
            'severity'         => 'critical',
            'status'           => 'open',
            'detection_source' => 'policy_daemon',
            'summary'          => 'Other tenant breached threshold.',
            'occurred_at'      => now(),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $abuse = $response->json('abuse_and_security');
        $this->assertEquals('warning', $abuse['status']);
        $this->assertEquals(1, $abuse['open_incidents_count']);
        $this->assertCount(1, $abuse['incidents']);
        $this->assertEquals('consecutive_hard_bounces', $abuse['incidents'][0]['incident_type']);
    }

    public function test_dashboard_abuse_and_security_redacts_internal_paths_and_admin_secrets(): void
    {
        AbuseIncident::create([
            'tenant_id'        => $this->tenant->id,
            'incident_type'    => 'consecutive_hard_bounces',
            'severity'         => 'medium',
            'status'           => 'open',
            'detection_source' => 'log_parser',
            'summary'          => 'High bounce activity observed.',
            'evidence'         => [
                'stack_trace'  => 'internal exception at /var/www/secret/file.php:42',
                'private_key'  => '-----BEGIN RSA PRIVATE KEY-----',
                'internal_ip'  => '10.0.0.15',
            ],
            'resolved_by'      => $this->otherTenant->id, // Existing user ID
            'resolution_notes' => 'Internal admin note: investigate server logs',
            'occurred_at'      => now(),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $incident = $response->json('abuse_and_security.incidents.0');

        // Verify sensitive fields are strictly not exposed in tenant response
        $this->assertArrayNotHasKey('evidence', $incident);
        $this->assertArrayNotHasKey('resolved_by', $incident);
        $this->assertArrayNotHasKey('resolution_notes', $incident);
        $this->assertArrayNotHasKey('stack_trace', $incident);
    }

    public function test_dashboard_abuse_and_security_provides_actionable_remediation_guidance(): void
    {
        AbuseIncident::create([
            'tenant_id'        => $this->tenant->id,
            'incident_type'    => 'daily_hard_bounce_threshold',
            'severity'         => 'high',
            'status'           => 'open',
            'summary'          => 'Daily hard bounce threshold exceeded.',
            'occurred_at'      => now(),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $incident = $response->json('abuse_and_security.incidents.0');
        $this->assertNotEmpty($incident['suggested_remediation']);
        $this->assertStringContainsString('scrub your recipient list', $incident['suggested_remediation']);
    }

    public function test_dashboard_dmarc_reporting_truthfully_reports_parser_available_and_automated_collection_pending(): void
    {
        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $dmarc = $response->json('dmarc_reporting');
        $this->assertTrue($dmarc['parser_available']);
        $this->assertFalse($dmarc['automated_collection']);
        $this->assertEquals('PENDING INFRASTRUCTURE & ARCHITECTURAL APPROVAL', $dmarc['automated_collection_status']);
        $this->assertStringContainsString('requires dedicated mail-routing infrastructure', $dmarc['automated_collection_note']);
    }

    public function test_tenant_security_incidents_endpoint_is_strictly_tenant_scoped_and_paginated(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            AbuseIncident::create([
                'tenant_id'     => $this->tenant->id,
                'incident_type' => 'quota_exhaustion',
                'severity'      => 'medium',
                'status'        => $i % 2 === 0 ? 'open' : 'resolved',
                'summary'       => "Quota incident {$i}",
                'occurred_at'   => now()->subDays($i),
            ]);
        }

        // Other tenant's incident
        AbuseIncident::create([
            'tenant_id'     => $this->otherTenant->id,
            'incident_type' => 'consecutive_hard_bounces',
            'severity'      => 'high',
            'status'        => 'open',
            'summary'       => 'Secret incident for other tenant',
            'occurred_at'   => now(),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/security/incidents?per_page=10&page=1');
        $response->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertEquals(20, $response->json('meta.total'));

        // Test status filtering
        $openResponse = $this->actingAs($this->tenant)->getJson('/api/security/incidents?status=open');
        $openResponse->assertOk();
        $this->assertEquals(10, $openResponse->json('meta.total'));
    }

    public function test_tenant_cannot_view_another_tenants_security_incidents(): void
    {
        AbuseIncident::create([
            'tenant_id'     => $this->otherTenant->id,
            'incident_type' => 'daily_hard_bounce_threshold',
            'severity'      => 'critical',
            'status'        => 'open',
            'summary'       => 'Other tenant incident',
            'occurred_at'   => now(),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/security/incidents');
        $response->assertOk();
        $this->assertEquals(0, $response->json('meta.total'));
    }

    public function test_dashboard_preserves_backwards_compatible_fields(): void
    {
        $domain = Domain::create([
            'user_id'     => $this->tenant->id,
            'domain_name' => 'legacy-domain.com',
            'status'      => 'active',
        ]);

        $invoice = Invoice::create([
            'user_id'        => $this->tenant->id,
            'plan_id'        => $this->plan->id,
            'invoice_number' => 'INV-2026-0001',
            'billing_cycle'  => 'monthly',
            'subtotal'       => 29.99,
            'total'          => 29.99,
            'currency'       => 'USD',
            'status'         => 'paid',
            'due_date'       => now()->addDays(7),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/dashboard');
        $response->assertOk();

        $this->assertEquals(1, $response->json('domain_count'));
        $this->assertEquals(1, $response->json('domains_count'));
        $this->assertEquals(0, $response->json('mailbox_count'));
        $this->assertEquals(0, $response->json('mailboxes_count'));
        $this->assertEquals('Professional', $response->json('plan_info.name'));
        $this->assertNotEmpty($response->json('recent_domains'));
        $this->assertNotEmpty($response->json('recent_invoices'));
    }
}
