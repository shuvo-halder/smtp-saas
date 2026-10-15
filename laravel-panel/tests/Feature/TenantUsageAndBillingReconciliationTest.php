<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OutboundQuotaService;
use App\Services\Reconciliation\TenantUsageReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TenantUsageAndBillingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private OutboundQuotaService $quotaService;
    private BillingService $billingService;
    private TenantUsageReconciliationService $reconciliationService;
    private Plan $standardPlan;
    private string $todayUtc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotaService = app(OutboundQuotaService::class);
        $this->billingService = app(BillingService::class);
        $this->reconciliationService = app(TenantUsageReconciliationService::class);
        $this->todayUtc = Carbon::now('UTC')->format('Y-m-d');

        $this->standardPlan = Plan::create([
            'name' => 'Reconciliation Test Plan',
            'slug' => 'recon-plan',
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox' => 1024,
            'max_aliases_per_domain' => 10,
            'price_monthly' => 100,
            'price_yearly' => 1000,
            'daily_outbound_recipients' => 50,
            'mailbox_daily_outbound_recipients' => 20,
        ]);
    }

    private function createTenantHierarchy(array $userAttrs = [], array $planAttrs = []): array
    {
        $plan = !empty($planAttrs) ? Plan::create(array_merge([
            'name' => 'Custom Plan',
            'slug' => 'custom-' . uniqid(),
            'max_domains' => 2,
            'max_mailboxes_per_domain' => 5,
            'storage_mb_per_mailbox' => 512,
            'price_monthly' => 50,
            'price_yearly' => 500,
            'daily_outbound_recipients' => 100,
            'mailbox_daily_outbound_recipients' => 25,
        ], $planAttrs)) : $this->standardPlan;

        $tenant = User::factory()->create(array_merge([
            'status' => 'active',
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addMonth(),
        ], $userAttrs));

        $domain = Domain::create([
            'user_id' => $tenant->id,
            'domain_name' => 'domain-' . uniqid() . '.com',
            'status' => 'active',
            'mx_verified' => true,
        ]);

        $mailbox = Mailbox::create([
            'domain_id' => $domain->id,
            'local_part' => 'info',
            'email' => 'info@' . $domain->domain_name,
            'password' => 'secret_hash',
            'quota_mb' => 512,
            'is_active' => true,
        ]);

        return [$tenant, $domain, $mailbox, $plan];
    }

    // ─── 1. Quota Consumption & Boundary Enforcement ────────────────────────────

    public function test_quota_consumption_boundary_enforcement(): void
    {
        [$tenant, $domain, $mailbox] = $this->createTenantHierarchy([], [
            'daily_outbound_recipients' => 10,
            'mailbox_daily_outbound_recipients' => 10,
        ]);

        $tenantKey = "outbound:tenant:{$tenant->id}:recipients:daily:{$this->todayUtc}";
        $mailboxKey = "outbound:mailbox:{$mailbox->id}:recipients:daily:{$this->todayUtc}";

        // Mock Redis evaluation sequence:
        // 1st request: 5 requested, current 0 -> allow (1)
        Redis::shouldReceive('eval')
            ->once()
            ->with(\Mockery::any(), 2, $tenantKey, $mailboxKey, 5, 10, 10, 172800)
            ->andReturn(1);

        $res1 = $this->quotaService->consume($tenant, $mailbox, 5);
        $this->assertEquals('ALLOWED', $res1['status']);
        $this->assertEquals(5, $res1['consumed']);

        // 2nd request: 5 requested, current 5 -> allow (1)
        Redis::shouldReceive('eval')
            ->once()
            ->with(\Mockery::any(), 2, $tenantKey, $mailboxKey, 5, 10, 10, 172800)
            ->andReturn(1);

        $res2 = $this->quotaService->consume($tenant, $mailbox, 5);
        $this->assertEquals('ALLOWED', $res2['status']);

        // 3rd request: 1 requested, current 10 -> reject (0)
        Redis::shouldReceive('eval')
            ->once()
            ->with(\Mockery::any(), 2, $tenantKey, $mailboxKey, 1, 10, 10, 172800)
            ->andReturn(0);

        $res3 = $this->quotaService->consume($tenant, $mailbox, 1);
        $this->assertEquals('REJECTED_QUOTA', $res3['status']);
    }

    public function test_unlimited_tenant_with_restricted_mailbox_quota_tracks_tenant_usage(): void
    {
        [$tenant, $domain, $mailbox] = $this->createTenantHierarchy([], [
            'daily_outbound_recipients' => -1, // Unlimited tenant
            'mailbox_daily_outbound_recipients' => 20, // Restricted mailbox
        ]);

        $tenantKey = "outbound:tenant:{$tenant->id}:recipients:daily:{$this->todayUtc}";
        $mailboxKey = "outbound:mailbox:{$mailbox->id}:recipients:daily:{$this->todayUtc}";

        // Tenant key MUST be passed even when tenant limit is -1
        Redis::shouldReceive('eval')
            ->once()
            ->with(\Mockery::any(), 2, $tenantKey, $mailboxKey, 4, -1, 20, 172800)
            ->andReturn(1);

        $res = $this->quotaService->consume($tenant, $mailbox, 4);
        $this->assertEquals('ALLOWED', $res['status']);
        $this->assertEquals(4, $res['consumed']);
    }

    public function test_disabled_quota_rejects_without_evaluating_redis(): void
    {
        [$tenant, $domain, $mailbox] = $this->createTenantHierarchy([], [
            'daily_outbound_recipients' => 0,
        ]);

        Redis::shouldReceive('eval')->never();

        $res = $this->quotaService->consume($tenant, $mailbox, 1);
        $this->assertEquals('REJECTED_QUOTA', $res['status']);
    }

    // ─── 2. Payment & Invoice Reconciliation ────────────────────────────────────

    private function buildValidIpnPayload(Invoice $invoice, array $overrides = []): array
    {
        $storePass = config('services.sslcommerz.store_pass');
        $preSign = 'tran_id=' . $invoice->invoice_number . '&store_passwd=' . md5($storePass);
        $validSign = md5($preSign);

        return array_merge([
            'tran_id' => $invoice->invoice_number,
            'verify_key' => 'tran_id',
            'verify_sign' => $validSign,
            'status' => 'VALID',
            'amount' => (string) $invoice->total,
            'currency' => $invoice->currency,
            'bank_tran_id' => 'BANK-' . uniqid(),
        ], $overrides);
    }

    public function test_payment_ipn_verification_success_activates_tenant_and_extends_expiry(): void
    {
        $tenant = User::factory()->create([
            'status' => 'suspended',
            'plan_id' => $this->standardPlan->id,
            'plan_expires_at' => now()->subDays(2),
        ]);

        $suspendedDomain = Domain::create([
            'user_id' => $tenant->id,
            'domain_name' => 'suspended.com',
            'status' => 'suspended',
            'mx_verified' => true,
        ]);

        $pendingDomain = Domain::create([
            'user_id' => $tenant->id,
            'domain_name' => 'pending.com',
            'status' => 'pending',
            'mx_verified' => false,
        ]);

        $invoice = Invoice::create([
            'user_id' => $tenant->id,
            'plan_id' => $this->standardPlan->id,
            'invoice_number' => 'INV-TEST-001',
            'billing_cycle' => 'monthly',
            'subtotal' => 100,
            'total' => 100,
            'currency' => 'BDT',
            'status' => 'pending',
            'due_date' => now()->addDays(3),
        ]);

        $payload = $this->buildValidIpnPayload($invoice, [
            'bank_tran_id' => 'BANK-TRX-999',
        ]);

        $result = $this->billingService->handleIpn($payload);
        $this->assertTrue($result);

        $tenant->refresh();
        $invoice->refresh();
        $suspendedDomain->refresh();
        $pendingDomain->refresh();

        $this->assertEquals('active', $tenant->status);
        $this->assertEquals('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertEquals('BANK-TRX-999', $invoice->gateway_transaction_id);
        $this->assertTrue($tenant->plan_expires_at->isFuture());

        // Domain invariant: suspended domain is reactivated, pending domain remains pending
        $this->assertEquals('active', $suspendedDomain->status);
        $this->assertEquals('pending', $pendingDomain->status);
    }

    public function test_payment_ipn_rejection_on_currency_mismatch(): void
    {
        $tenant = User::factory()->create(['status' => 'pending']);
        $invoice = Invoice::create([
            'user_id' => $tenant->id,
            'plan_id' => $this->standardPlan->id,
            'invoice_number' => 'INV-CURR-001',
            'billing_cycle' => 'monthly',
            'subtotal' => 100,
            'total' => 100,
            'currency' => 'BDT',
            'status' => 'pending',
            'due_date' => now()->addDays(3),
        ]);

        $payload = $this->buildValidIpnPayload($invoice, [
            'currency' => 'USD', // Mismatch!
        ]);

        $result = $this->billingService->handleIpn($payload);

        $this->assertFalse($result);
        $this->assertEquals('pending', $invoice->fresh()->status);
    }

    public function test_payment_ipn_rejection_on_amount_mismatch(): void
    {
        $tenant = User::factory()->create(['status' => 'pending']);
        $invoice = Invoice::create([
            'user_id' => $tenant->id,
            'plan_id' => $this->standardPlan->id,
            'invoice_number' => 'INV-AMT-001',
            'billing_cycle' => 'monthly',
            'subtotal' => 100,
            'total' => 100,
            'currency' => 'BDT',
            'status' => 'pending',
            'due_date' => now()->addDays(3),
        ]);

        $payload = $this->buildValidIpnPayload($invoice, [
            'amount' => '49.99', // Mismatch!
        ]);

        $result = $this->billingService->handleIpn($payload);

        $this->assertFalse($result);
        $this->assertEquals('pending', $invoice->fresh()->status);
    }

    public function test_payment_ipn_rejection_on_cancelled_invoice(): void
    {
        $tenant = User::factory()->create(['status' => 'pending']);
        $invoice = Invoice::create([
            'user_id' => $tenant->id,
            'plan_id' => $this->standardPlan->id,
            'invoice_number' => 'INV-CANC-001',
            'billing_cycle' => 'monthly',
            'subtotal' => 100,
            'total' => 100,
            'currency' => 'BDT',
            'status' => 'cancelled', // Cancelled!
            'due_date' => now()->addDays(3),
        ]);

        $payload = $this->buildValidIpnPayload($invoice);

        $result = $this->billingService->handleIpn($payload);

        $this->assertFalse($result);
        $this->assertEquals('cancelled', $invoice->fresh()->status);
    }

    public function test_admin_activation_rejects_expired_tenant(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $tenant = User::factory()->create([
            'status' => 'suspended',
            'plan_id' => $this->standardPlan->id,
            'plan_expires_at' => now()->subDays(10), // Expired!
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$tenant->id}/activate");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot activate tenant with an expired subscription. Tenant must renew subscription or have plan expiration extended.',
            ]);

        $this->assertEquals('suspended', $tenant->fresh()->status);
    }

    // ─── 3. Reconciliation Service Invariant Detections ─────────────────────────

    public function test_reconciliation_service_detects_redis_mariadb_ledger_desync(): void
    {
        [$tenant] = $this->createTenantHierarchy();

        // Create paid invoice so ACTIVE_TENANT_NO_PAID_INVOICE doesn't flag
        Invoice::create([
            'user_id' => $tenant->id,
            'plan_id' => $this->standardPlan->id,
            'invoice_number' => 'INV-SYNC-001',
            'billing_cycle' => 'monthly',
            'subtotal' => 100,
            'total' => 100,
            'currency' => 'BDT',
            'status' => 'paid',
            'paid_at' => now(),
            'due_date' => now(),
        ]);

        $tenantKey = "outbound:tenant:{$tenant->id}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('get')->with($tenantKey)->andReturn('45');
        Redis::shouldReceive('get')->andReturn('0'); // Mailbox keys

        // MariaDB ledger only holds 10
        TenantOutboundUsage::create([
            'user_id' => $tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 10,
        ]);

        $report = $this->reconciliationService->reconcile($tenant->id, $this->todayUtc);

        $codes = array_column($report['discrepancies'], 'code');
        $this->assertContains('USAGE_LEDGER_DESYNC', $codes);
    }

    public function test_reconciliation_service_detects_active_expired_subscription(): void
    {
        [$tenant] = $this->createTenantHierarchy([
            'status' => 'active',
            'plan_expires_at' => now()->subDay(), // In the past!
        ]);

        Redis::shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('get')->andReturn('0');

        $report = $this->reconciliationService->reconcile($tenant->id, $this->todayUtc);

        $this->assertFalse($report['summary']['is_healthy']);
        $codes = array_column($report['discrepancies'], 'code');
        $this->assertContains('ACTIVE_EXPIRED_SUBSCRIPTION', $codes);
    }

    public function test_reconciliation_service_detects_suspended_tenant_with_active_domains(): void
    {
        [$tenant, $domain] = $this->createTenantHierarchy([
            'status' => 'suspended',
        ]);
        // Domain is still 'active' despite tenant being suspended
        $domain->update(['status' => 'active']);

        Redis::shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('get')->andReturn('0');

        $report = $this->reconciliationService->reconcile($tenant->id, $this->todayUtc);

        $codes = array_column($report['discrepancies'], 'code');
        $this->assertContains('SUSPENDED_TENANT_ACTIVE_DOMAINS', $codes);
    }

    public function test_reconciliation_service_detects_over_quota_tenant(): void
    {
        [$tenant] = $this->createTenantHierarchy([], [
            'daily_outbound_recipients' => 50,
        ]);

        $tenantKey = "outbound:tenant:{$tenant->id}:recipients:daily:{$this->todayUtc}";

        Redis::shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('get')->with($tenantKey)->andReturn('75'); // Over 50!
        Redis::shouldReceive('get')->andReturn('0');

        $report = $this->reconciliationService->reconcile($tenant->id, $this->todayUtc);

        $codes = array_column($report['discrepancies'], 'code');
        $this->assertContains('TENANT_OVER_QUOTA', $codes);
        $this->assertEquals(1, $report['summary']['over_quota_tenants_count']);

        $tenantSnapshot = $report['tenants'][0];
        $this->assertTrue($tenantSnapshot['is_over_quota']);
        $this->assertEquals(0, $tenantSnapshot['remaining_daily_quota']);
    }

    public function test_reconciliation_service_detects_paid_invoice_missing_paid_at(): void
    {
        [$tenant] = $this->createTenantHierarchy();

        Invoice::create([
            'user_id' => $tenant->id,
            'plan_id' => $this->standardPlan->id,
            'invoice_number' => 'INV-PAID-NOPAYDATE',
            'billing_cycle' => 'monthly',
            'subtotal' => 100,
            'total' => 100,
            'currency' => 'BDT',
            'status' => 'paid',
            'paid_at' => null, // Missing!
            'due_date' => now(),
        ]);

        Redis::shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('get')->andReturn('0');

        $report = $this->reconciliationService->reconcile($tenant->id, $this->todayUtc);

        $codes = array_column($report['discrepancies'], 'code');
        $this->assertContains('PAID_INVOICE_MISSING_PAID_AT', $codes);
    }

    // ─── 4. Command & API Endpoint Execution ────────────────────────────────────

    public function test_reconciliation_command_runs_cleanly(): void
    {
        $this->artisan('tenant:reconcile-usage')
            ->expectsOutputToContain('Tenant Usage, Quota & Billing Reconciliation')
            ->assertExitCode(0);
    }

    public function test_reconciliation_command_json_output(): void
    {
        $this->artisan('tenant:reconcile-usage --json')
            ->assertExitCode(0);
    }

    public function test_admin_reconciliation_api_endpoints_authorization(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $tenant = User::factory()->create(['status' => 'active']);

        // Ordinary tenant is denied
        $this->actingAs($tenant)->getJson('/api/admin/reconciliation/report')
            ->assertStatus(403);

        // Super Admin is authorized
        $response = $this->actingAs($admin)->getJson('/api/admin/reconciliation/report');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'checked_at',
                'reconciliation_date',
                'telemetry_available',
                'summary' => [
                    'total_tenants_checked',
                    'clean_tenants_count',
                    'is_healthy',
                ],
                'discrepancies',
                'tenants',
            ]);

        // Single tenant endpoint
        $tenantResponse = $this->actingAs($admin)->getJson("/api/admin/reconciliation/tenants/{$tenant->id}");
        $tenantResponse->assertStatus(200)
            ->assertJsonStructure([
                'checked_at',
                'reconciliation_date',
                'tenant',
                'discrepancies',
            ]);
    }
}
