<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Plan;
use App\Models\Invoice;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Services\BillingService;
use App\Console\Commands\SuspendExpiredTenants;
use App\Models\AuditLog;
use App\Enums\RoleEnum;
use Illuminate\Support\Facades\Artisan;

class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Plan $monthlyPlan;
    private BillingService $billingService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->monthlyPlan = Plan::create([
            'name' => 'Pro Monthly',
            'slug' => 'pro-monthly',
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox' => 1024,
            'max_aliases_per_domain' => 10,
            'price_monthly' => 100,
            'price_yearly' => 1000,
        ]);
        
        $this->billingService = app(BillingService::class);
    }

    public function test_first_payment_activates_tenant()
    {
        $user = cloneUser('pending');
        
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'plan_id' => $this->monthlyPlan->id,
            'invoice_number' => 'INV-001',
            'billing_cycle' => 'monthly',
            'subtotal' => 100,
            'total' => 100,
            'due_date' => now(),
            'status' => 'pending'
        ]);

        $this->billingService->markInvoicePaid($invoice, []);

        $user->refresh();
        $this->assertEquals('active', $user->status);
        $this->assertNotNull($user->plan_expires_at);
        $this->assertTrue($user->plan_expires_at->isFuture());
    }

    public function test_renewal_before_expiry_preserves_time()
    {
        $user = cloneUser('active');
        $originalExpiry = now()->addDays(15);
        $user->update(['plan_expires_at' => $originalExpiry]);
        
        $invoice = cloneInvoice($user, $this->monthlyPlan);

        $this->billingService->markInvoicePaid($invoice, []);

        $user->refresh();
        // Expiration should be original + 1 month
        $expected = (clone $originalExpiry)->addMonth();
        $this->assertEquals($expected->toDateString(), $user->plan_expires_at->toDateString());
    }

    public function test_renewal_after_expiry_starts_from_now()
    {
        $user = cloneUser('suspended');
        $user->update(['plan_expires_at' => now()->subDays(5)]);
        
        $invoice = cloneInvoice($user, $this->monthlyPlan);

        $this->billingService->markInvoicePaid($invoice, []);

        $user->refresh();
        $this->assertEquals('active', $user->status);
        
        // Expiration should be now + 1 month, NOT original + 1 month
        $expected = now()->addMonth();
        $this->assertEquals($expected->toDateString(), $user->plan_expires_at->toDateString());
    }

    public function test_suspended_tenant_recovery_reactivates_domains()
    {
        $user = cloneUser('suspended');
        $user->update(['plan_expires_at' => now()->subDays(5)]);
        
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'example.com',
            'status' => 'suspended'
        ]);

        $invoice = cloneInvoice($user, $this->monthlyPlan);
        $this->billingService->markInvoicePaid($invoice, []);

        $user->refresh();
        $domain->refresh();
        
        $this->assertEquals('active', $user->status);
        $this->assertEquals('active', $domain->status);
    }

    public function test_failed_payment_does_not_activate_tenant()
    {
        $user = cloneUser('pending');
        $invoice = cloneInvoice($user, $this->monthlyPlan);
        
        // Simulate FAILED IPN
        $result = $this->billingService->handleIpn([
            'tran_id' => $invoice->invoice_number,
            'status' => 'FAILED',
            'verify_sign' => 'invalid',
            'verify_key' => 'invalid'
        ]);

        $user->refresh();
        $invoice->refresh();

        $this->assertEquals('pending', $user->status);
    }

    public function test_duplicate_ipn_does_not_extend_multiple_times()
    {
        $user = cloneUser('pending');
        $invoice = cloneInvoice($user, $this->monthlyPlan);

        // First successful mark
        $this->billingService->markInvoicePaid($invoice, []);
        
        $user->refresh();
        $firstExpiry = $user->plan_expires_at;

        // Create a valid IPN payload
        $storePass = config('services.sslcommerz.store_pass');
        $preSign = 'tran_id=' . $invoice->invoice_number . '&store_passwd=' . md5($storePass);
        $validSign = md5($preSign);

        $billingService = app(BillingService::class);
        $result = $billingService->handleIpn([
            'tran_id' => $invoice->invoice_number,
            'verify_key' => 'tran_id',
            'verify_sign' => $validSign,
            'status' => 'VALID',
            'amount' => 100,
        ]);
        
        $user->refresh();
        $this->assertEquals($firstExpiry->toDateTimeString(), $user->plan_expires_at->toDateTimeString());
        $this->assertTrue($result, 'Handle IPN should return true for already paid invoice');
    }

    public function test_expired_tenant_gets_suspended()
    {
        $user = cloneUser('active');
        $user->update(['plan_expires_at' => now()->subHours(1)]);
        
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'test.com',
            'status' => 'active'
        ]);

        Artisan::call('tenant:suspend-expired');

        $user->refresh();
        $domain->refresh();

        $this->assertEquals('suspended', $user->status);
        $this->assertEquals('suspended', $domain->status);
    }

    public function test_non_expired_tenant_remains_active()
    {
        $user = cloneUser('active');
        $user->update(['plan_expires_at' => now()->addHours(1)]);
        
        Artisan::call('tenant:suspend-expired');

        $user->refresh();
        $this->assertEquals('active', $user->status);
    }

    public function test_expired_administrator_is_exempt_from_tenant_suspension()
    {
        // Customer tenant with expired subscription
        $tenant = cloneUser('active');
        $tenant->update(['plan_expires_at' => now()->subHours(1), 'is_admin' => false]);
        $tenantDomain = Domain::create([
            'user_id' => $tenant->id,
            'domain_name' => 'customer-domain.com',
            'status' => 'active',
        ]);

        // Administrator account with expired plan_expires_at timestamp
        $admin = cloneUser('active');
        $admin->update(['plan_expires_at' => now()->subHours(1), 'is_admin' => true]);
        $adminDomain = Domain::create([
            'user_id' => $admin->id,
            'domain_name' => 'admin-domain.com',
            'status' => 'active',
        ]);

        Artisan::call('tenant:suspend-expired');

        $tenant->refresh();
        $tenantDomain->refresh();
        $admin->refresh();
        $adminDomain->refresh();

        // Customer tenant must be suspended
        $this->assertEquals('suspended', $tenant->status);
        $this->assertEquals('suspended', $tenantDomain->status);

        // Administrator account must be completely exempt from tenant suspension
        $this->assertEquals('active', $admin->status);
        $this->assertEquals('active', $adminDomain->status);
    }

    public function test_expired_administrator_with_rbac_role_is_exempt_from_tenant_suspension(): void
    {
        // Customer tenant with expired subscription
        $tenant = cloneUser('active');
        $tenant->update(['plan_expires_at' => now()->subHours(1), 'is_admin' => false]);
        $tenantDomain = Domain::create([
            'user_id' => $tenant->id,
            'domain_name' => 'customer-role-test.com',
            'status' => 'active',
        ]);

        // Administrator holding Spatie super_admin role, even if is_admin is false
        $admin = cloneUser('active');
        $admin->update(['plan_expires_at' => now()->subHours(1), 'is_admin' => false]);
        $admin->assignRole(RoleEnum::SUPER_ADMIN->value);
        $adminDomain = Domain::create([
            'user_id' => $admin->id,
            'domain_name' => 'admin-role-domain.com',
            'status' => 'active',
        ]);

        Artisan::call('tenant:suspend-expired');

        $tenant->refresh();
        $tenantDomain->refresh();
        $admin->refresh();
        $adminDomain->refresh();

        // Tenant is suspended
        $this->assertEquals('suspended', $tenant->status);
        $this->assertEquals('suspended', $tenantDomain->status);

        // RBAC Role-holding Admin is completely exempt
        $this->assertEquals('active', $admin->status);
        $this->assertEquals('active', $adminDomain->status);
    }

    public function test_free_plan_user_with_null_expiry_is_not_suspended(): void
    {
        $user = cloneUser('active');
        $user->update(['plan_expires_at' => null, 'is_admin' => false]);
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'lifetime-free.com',
            'status' => 'active',
        ]);

        Artisan::call('tenant:suspend-expired');

        $user->refresh();
        $domain->refresh();

        $this->assertEquals('active', $user->status);
        $this->assertEquals('active', $domain->status);
    }

    public function test_ipn_currency_mismatch_is_rejected(): void
    {
        $user = cloneUser('pending');
        $invoice = cloneInvoice($user, $this->monthlyPlan);
        $this->assertEquals('BDT', $invoice->currency);

        $storePass = config('services.sslcommerz.store_pass');
        $preSign = 'tran_id=' . $invoice->invoice_number . '&store_passwd=' . md5($storePass);
        $validSign = md5($preSign);

        // Send IPN with currency mismatch (USD instead of BDT)
        $result = $this->billingService->handleIpn([
            'tran_id' => $invoice->invoice_number,
            'verify_key' => 'tran_id',
            'verify_sign' => $validSign,
            'status' => 'VALID',
            'amount' => 100,
            'currency' => 'USD',
        ]);

        $this->assertFalse($result, 'Handle IPN must reject currency mismatch');

        $invoice->refresh();
        $user->refresh();

        $this->assertEquals('pending', $invoice->status);
        $this->assertEquals('pending', $user->status);
    }

    public function test_concurrent_duplicate_ipn_is_idempotent_under_pessimistic_lock(): void
    {
        $user = cloneUser('pending');
        $invoice = cloneInvoice($user, $this->monthlyPlan);

        // First payment callback
        $this->billingService->markInvoicePaid($invoice, ['bank_tran_id' => 'TXN-FIRST-001']);
        $user->refresh();
        $invoice->refresh();

        $firstExpiry = $user->plan_expires_at;
        $firstPaidAt = $invoice->paid_at;
        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals('active', $user->status);

        // Second payment callback for the exact same invoice (e.g. concurrent webhook retry)
        $this->billingService->markInvoicePaid($invoice, ['bank_tran_id' => 'TXN-DUPLICATE-002']);
        $user->refresh();
        $invoice->refresh();

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals($firstExpiry->toDateTimeString(), $user->plan_expires_at->toDateTimeString());
        $this->assertEquals($firstPaidAt->toDateTimeString(), $invoice->paid_at->toDateTimeString());
    }

    public function test_mailbox_disabled_state_is_preserved_across_billing_reactivation(): void
    {
        $user = cloneUser('suspended');
        $user->update(['plan_expires_at' => now()->subDays(5)]);

        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'preserve-mailbox.com',
            'status' => 'suspended',
            'mx_verified' => true,
        ]);

        // Mailbox 1: Active
        $mailboxActive = Mailbox::create([
            'domain_id' => $domain->id,
            'local_part' => 'activebox',
            'email' => 'activebox@preserve-mailbox.com',
            'password' => crypt('Secret123!', '$6$rounds=5000$saltsalt$'),
            'quota_mb' => 1024,
            'is_active' => true,
        ]);

        // Mailbox 2: Independently disabled (e.g. security abuse or admin hold)
        $mailboxDisabled = Mailbox::create([
            'domain_id' => $domain->id,
            'local_part' => 'disabledbox',
            'email' => 'disabledbox@preserve-mailbox.com',
            'password' => crypt('Secret123!', '$6$rounds=5000$saltsalt$'),
            'quota_mb' => 1024,
            'is_active' => false,
        ]);

        $invoice = cloneInvoice($user, $this->monthlyPlan);
        $this->billingService->markInvoicePaid($invoice, []);

        $user->refresh();
        $domain->refresh();
        $mailboxActive->refresh();
        $mailboxDisabled->refresh();

        $this->assertEquals('active', $user->status);
        $this->assertEquals('active', $domain->status);
        $this->assertTrue($mailboxActive->is_active, 'Active mailbox should remain active');
        $this->assertFalse($mailboxDisabled->is_active, 'Independently disabled mailbox must remain disabled upon billing renewal');
    }

    public function test_auto_suspension_creates_audit_log(): void
    {
        $user = cloneUser('active');
        $user->update(['plan_expires_at' => now()->subHours(2), 'is_admin' => false]);
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'audit-test.com',
            'status' => 'active',
        ]);

        Artisan::call('tenant:suspend-expired');

        $user->refresh();
        $this->assertEquals('suspended', $user->status);

        $log = AuditLog::where('action', 'tenant.auto_suspend')
            ->where('entity_id', $user->id)
            ->first();

        $this->assertNotNull($log, 'AuditLog must exist for auto-suspended tenant');
        $this->assertEquals('User', $log->entity_type);
        $this->assertNull($log->actor_user_id, 'System cron action must have null actor_user_id');
        $this->assertEquals('active', $log->before_state['status']);
        $this->assertEquals('suspended', $log->after_state['status']);
    }

    public function test_payment_activation_creates_audit_log(): void
    {
        $user = cloneUser('pending');
        $invoice = cloneInvoice($user, $this->monthlyPlan);

        $this->billingService->markInvoicePaid($invoice, [
            'bank_tran_id' => 'BANK-TRX-998877',
        ]);

        $log = AuditLog::where('action', 'billing.invoice_paid')
            ->where('entity_id', $invoice->id)
            ->first();

        $this->assertNotNull($log, 'AuditLog must exist for paid invoice');
        $this->assertEquals('Invoice', $log->entity_type);
        $this->assertEquals($user->id, $log->actor_user_id);
        $this->assertEquals('paid', $log->after_state['invoice_status']);
        $this->assertEquals('active', $log->after_state['user_status']);
        $this->assertEquals('BANK-TRX-998877', $log->after_state['gateway_transaction_id']);
    }

    public function test_admin_suspend_and_activate_create_audit_logs(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $tenant = cloneUser('active');

        // 1. Suspend via Admin API
        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$tenant->id}/suspend", [
            'reason' => 'Compliance investigation',
        ]);
        $response->assertStatus(200);

        $suspendLog = AuditLog::where('action', 'admin.users.suspend')
            ->where('entity_id', $tenant->id)
            ->first();

        $this->assertNotNull($suspendLog, 'AuditLog must exist for admin suspend action');
        $this->assertEquals('User', $suspendLog->entity_type);
        $this->assertEquals($admin->id, $suspendLog->actor_user_id);
        $this->assertEquals('suspended', $suspendLog->after_state['status']);
        $this->assertEquals('Compliance investigation', $suspendLog->reason);

        // 2. Reactivate via Admin API
        $reactivateResponse = $this->actingAs($admin)->postJson("/api/admin/users/{$tenant->id}/activate", [
            'reason' => 'Investigation cleared',
        ]);
        $reactivateResponse->assertStatus(200);

        $activateLog = AuditLog::where('action', 'admin.users.activate')
            ->where('entity_id', $tenant->id)
            ->first();

        $this->assertNotNull($activateLog, 'AuditLog must exist for admin activate action');
        $this->assertEquals('User', $activateLog->entity_type);
        $this->assertEquals($admin->id, $activateLog->actor_user_id);
        $this->assertEquals('active', $activateLog->after_state['status']);
        $this->assertEquals('Investigation cleared', $activateLog->reason);
    }

    public function test_admin_suspend_only_suspends_active_domains_preserving_pending_domains(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $tenant = cloneUser('active');

        $activeDomain = Domain::create([
            'user_id' => $tenant->id,
            'domain_name' => 'active-domain.com',
            'status' => 'active',
            'mx_verified' => true,
        ]);

        $pendingDomain = Domain::create([
            'user_id' => $tenant->id,
            'domain_name' => 'pending-domain.com',
            'status' => 'pending',
            'mx_verified' => false,
        ]);

        // Suspend
        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$tenant->id}/suspend");
        $response->assertStatus(200);

        $activeDomain->refresh();
        $pendingDomain->refresh();

        $this->assertEquals('suspended', $activeDomain->status, 'Active domain must be suspended');
        $this->assertEquals('pending', $pendingDomain->status, 'Pending domain must remain pending');

        // Reactivate
        $reactivateResponse = $this->actingAs($admin)->postJson("/api/admin/users/{$tenant->id}/activate");
        $reactivateResponse->assertStatus(200);

        $activeDomain->refresh();
        $pendingDomain->refresh();

        $this->assertEquals('active', $activeDomain->status, 'Verified domain must be reactivated');
        $this->assertEquals('pending', $pendingDomain->status, 'Pending domain must remain pending');
    }
}

function cloneUser($status) {
    $email = 'test_' . uniqid() . '@example.com';
    return User::create([
        'name' => 'Test User',
        'email' => $email,
        'password' => bcrypt('password'),
        'status' => $status,
    ]);
}

function cloneInvoice($user, $plan) {
    return Invoice::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'invoice_number' => 'INV-'.uniqid(),
        'billing_cycle' => 'monthly',
        'subtotal' => 100,
        'total' => 100,
        'currency' => 'BDT',
        'due_date' => now(),
        'status' => 'pending'
    ]);
}
