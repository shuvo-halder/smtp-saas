<?php

namespace Tests\Feature;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantBillingManagementTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name' => 'Starter Business',
            'slug' => 'starter-business',
            'description' => 'Great for small businesses',
            'max_domains' => 3,
            'max_mailboxes_per_domain' => 5,
            'storage_mb_per_mailbox' => 2048,
            'max_aliases_per_domain' => 10,
            'daily_outbound_recipients' => 500,
            'mailbox_daily_outbound_recipients' => 100,
            'price_monthly' => 1500.00,
            'price_yearly' => 15000.00,
            'features' => ['Custom Domain', 'Webmail', 'Spam Filter'],
            'is_active' => true,
            'is_featured' => true,
            'sort_order' => 1,
        ]);

        $this->tenant = User::create([
            'name' => 'Acme Corp',
            'email' => 'admin@acme.com',
            'password' => bcrypt('Password123!'),
            'company_name' => 'Acme Inc',
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'plan_expires_at' => now()->addDays(20),
            'is_admin' => false,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_subscription_or_invoices(): void
    {
        $this->getJson('/api/billing/subscription')->assertStatus(401);
        $this->getJson('/api/billing/invoices')->assertStatus(401);
    }

    public function test_active_tenant_can_retrieve_subscription_and_usage_summary(): void
    {
        // Create 2 domains and 3 mailboxes
        $d1 = Domain::create(['user_id' => $this->tenant->id, 'domain_name' => 'acme1.com', 'status' => 'active']);
        $d2 = Domain::create(['user_id' => $this->tenant->id, 'domain_name' => 'acme2.com', 'status' => 'active']);

        Mailbox::create(['domain_id' => $d1->id, 'local_part' => 'info', 'email' => 'info@acme1.com', 'password' => 'secret', 'quota_mb' => 2048]);
        Mailbox::create(['domain_id' => $d1->id, 'local_part' => 'sales', 'email' => 'sales@acme1.com', 'password' => 'secret', 'quota_mb' => 2048]);
        Mailbox::create(['domain_id' => $d2->id, 'local_part' => 'support', 'email' => 'support@acme2.com', 'password' => 'secret', 'quota_mb' => 2048]);

        // Record today's outbound usage
        TenantOutboundUsage::create([
            'user_id' => $this->tenant->id,
            'usage_date' => now()->toDateString(),
            'recipient_count' => 120,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/billing/subscription');

        $response->assertStatus(200)
            ->assertJson([
                'plan' => [
                    'id' => $this->plan->id,
                    'name' => 'Starter Business',
                    'slug' => 'starter-business',
                ],
                'subscription' => [
                    'status' => 'active',
                    'is_active' => true,
                    'is_expiring_soon' => false,
                    'can_renew' => true,
                ],
                'usage' => [
                    'domains_used' => 2,
                    'domains_limit' => 3,
                    'mailboxes_used' => 3,
                    'mailboxes_limit' => 15,
                    'outbound_today' => 120,
                    'outbound_daily_limit' => 500,
                    'storage_mb_per_mailbox' => 2048,
                ],
            ]);

        $this->assertEquals(20, $response->json('subscription.days_remaining'));
    }

    public function test_expiring_soon_subscription_flags_warning(): void
    {
        $this->tenant->update(['plan_expires_at' => now()->addDays(5)]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/billing/subscription');

        $response->assertStatus(200);
        $this->assertTrue($response->json('subscription.is_expiring_soon'));
        $this->assertEquals('active', $response->json('subscription.status'));
        $this->assertEquals(5, $response->json('subscription.days_remaining'));
    }

    public function test_expired_tenant_can_access_billing_subscription_endpoint(): void
    {
        $this->tenant->update([
            'plan_expires_at' => now()->subDays(2),
            'status' => 'active', // Note: could be active but expired, or suspended
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/billing/subscription');

        $response->assertStatus(200);
        $this->assertEquals('expired', $response->json('subscription.status'));
        $this->assertFalse($response->json('subscription.is_active'));
        $this->assertEquals(0, $response->json('subscription.days_remaining'));
        $this->assertTrue($response->json('subscription.can_renew'));
    }

    public function test_suspended_tenant_can_access_billing_subscription_endpoint(): void
    {
        $this->tenant->update([
            'status' => 'suspended',
            'plan_expires_at' => now()->subDays(10),
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/billing/subscription');

        $response->assertStatus(200);
        $this->assertEquals('suspended', $response->json('subscription.status'));
        $this->assertFalse($response->json('subscription.is_active'));
    }

    public function test_tenant_without_plan_returns_inactive_state(): void
    {
        $orphanTenant = User::create([
            'name' => 'No Plan User',
            'email' => 'noplan@example.com',
            'password' => bcrypt('Password123!'),
            'status' => 'active',
            'plan_id' => null,
            'plan_expires_at' => null,
        ]);

        $response = $this->actingAs($orphanTenant, 'sanctum')
            ->getJson('/api/billing/subscription');

        $response->assertStatus(200);
        $this->assertNull($response->json('plan'));
        $this->assertEquals('inactive', $response->json('subscription.status'));
        $this->assertFalse($response->json('subscription.can_renew'));
    }

    public function test_tenant_can_list_their_invoices_with_pagination_and_status_filter(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Invoice::create([
                'user_id' => $this->tenant->id,
                'plan_id' => $this->plan->id,
                'invoice_number' => "INV-ACME-{$i}",
                'billing_cycle' => 'monthly',
                'subtotal' => 1500,
                'total' => 1500,
                'currency' => 'BDT',
                'status' => $i <= 3 ? 'paid' : 'pending',
                'due_date' => now()->addDays(3),
            ]);
        }

        // Other tenant's invoice
        $otherUser = User::create([
            'name' => 'Other Tenant',
            'email' => 'other@tenant.com',
            'password' => bcrypt('Password123!'),
            'status' => 'active',
        ]);
        Invoice::create([
            'user_id' => $otherUser->id,
            'plan_id' => $this->plan->id,
            'invoice_number' => 'INV-OTHER-99',
            'billing_cycle' => 'monthly',
            'subtotal' => 1500,
            'total' => 1500,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->addDays(3),
        ]);

        // Default pagination
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/billing/invoices?per_page=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(5, $response->json('meta.total'));

        // Filter by status=pending
        $filterResponse = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/billing/invoices?status=pending');

        $filterResponse->assertStatus(200);
        $this->assertCount(2, $filterResponse->json('data'));
        foreach ($filterResponse->json('data') as $inv) {
            $this->assertEquals('pending', $inv['status']);
        }

        // Bounded per_page (should clamp to max 50, min 1)
        $clampedResponse = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/billing/invoices?per_page=999');
        $clampedResponse->assertStatus(200);
        $this->assertEquals(50, $clampedResponse->json('meta.per_page'));
    }

    public function test_tenant_can_view_own_invoice_detail(): void
    {
        $invoice = Invoice::create([
            'user_id' => $this->tenant->id,
            'plan_id' => $this->plan->id,
            'invoice_number' => 'INV-DETAIL-01',
            'billing_cycle' => 'yearly',
            'subtotal' => 15000,
            'tax' => 0,
            'total' => 15000,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->subDays(5),
            'paid_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson("/api/billing/invoices/{$invoice->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $invoice->id,
                    'invoice_number' => 'INV-DETAIL-01',
                    'billing_cycle' => 'yearly',
                    'subtotal' => '15000.00',
                    'total' => '15000.00',
                    'currency' => 'BDT',
                    'status' => 'paid',
                ],
            ]);
    }

    public function test_cross_tenant_cannot_view_other_tenant_invoice(): void
    {
        $otherUser = User::create([
            'name' => 'Rival Corp',
            'email' => 'admin@rival.com',
            'password' => bcrypt('Password123!'),
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'user_id' => $this->tenant->id,
            'plan_id' => $this->plan->id,
            'invoice_number' => 'INV-SECRET-01',
            'billing_cycle' => 'monthly',
            'subtotal' => 1500,
            'total' => 1500,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now(),
        ]);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/billing/invoices/{$invoice->id}")
            ->assertStatus(403);
    }

    public function test_admin_with_permission_can_view_any_invoice(): void
    {
        Permission::firstOrCreate(['name' => PermissionEnum::ADMIN_INVOICES_READ->value, 'guard_name' => 'web']);

        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@system.local',
            'password' => bcrypt('Password123!'),
            'status' => 'active',
            'is_admin' => true,
        ]);
        $admin->givePermissionTo(PermissionEnum::ADMIN_INVOICES_READ->value);

        $invoice = Invoice::create([
            'user_id' => $this->tenant->id,
            'plan_id' => $this->plan->id,
            'invoice_number' => 'INV-ADMIN-INSPECT',
            'billing_cycle' => 'monthly',
            'subtotal' => 1500,
            'total' => 1500,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/billing/invoices/{$invoice->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.invoice_number', 'INV-ADMIN-INSPECT');
    }

    public function test_checkout_initiation_validates_payload(): void
    {
        $this->actingAs($this->tenant, 'sanctum')
            ->postJson('/api/billing/checkout', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan_id', 'billing_cycle']);

        $this->actingAs($this->tenant, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_id' => 99999,
                'billing_cycle' => 'invalid_cycle',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan_id', 'billing_cycle']);
    }

    public function test_checkout_initiation_contacts_gateway_and_returns_redirect(): void
    {
        Http::fake([
            'https://sandbox.sslcommerz.com/*' => Http::response([
                'status' => 'SUCCESS',
                'sessionkey' => 'MOCK_SESSION_KEY_123',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/bank_list.php?session=MOCK_SESSION_KEY_123',
            ], 200),
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_id' => $this->plan->id,
                'billing_cycle' => 'yearly',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'redirect_url' => 'https://sandbox.sslcommerz.com/gwprocess/v4/bank_list.php?session=MOCK_SESSION_KEY_123',
            ]);

        $this->assertDatabaseHas('invoices', [
            'user_id' => $this->tenant->id,
            'plan_id' => $this->plan->id,
            'billing_cycle' => 'yearly',
            'status' => 'pending',
            'transaction_id' => 'MOCK_SESSION_KEY_123',
            'subtotal' => 15000.00,
            'total' => 15000.00,
        ]);
    }
}
