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
        'due_date' => now(),
        'status' => 'pending'
    ]);
}
