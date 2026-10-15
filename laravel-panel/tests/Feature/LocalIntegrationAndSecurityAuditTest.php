<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Backup\BackupEncryptionService;
use App\Services\BillingService;
use App\Services\OutboundQuotaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LocalIntegrationAndSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions for Spatie Permission in testing
        foreach (RoleEnum::cases() as $role) {
            Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web']);
        }
    }

    private function createTestPlan(string $slug = 'starter-test'): Plan
    {
        return Plan::create([
            'name'                              => 'Starter',
            'slug'                              => $slug,
            'max_domains'                       => 1,
            'max_mailboxes_per_domain'          => 5,
            'storage_mb_per_mailbox'            => 1024,
            'max_aliases_per_domain'            => 5,
            'daily_outbound_recipients'         => 500,
            'mailbox_daily_outbound_recipients' => 100,
            'price_monthly'                     => 199.00,
            'price_yearly'                      => 1990.00,
            'is_active'                         => true,
        ]);
    }

    /** 1. Unauthenticated user requests admin endpoint -> HTTP 401 */
    public function test_unauthenticated_user_requests_admin_endpoint_returns_401(): void
    {
        $response = $this->getJson('/api/admin/users');
        $response->assertStatus(401);
    }

    /** 2. Tenant accesses unauthorized admin endpoint -> HTTP 403 */
    public function test_tenant_accessing_unauthorized_admin_endpoint_returns_403(): void
    {
        $tenant = User::factory()->create([
            'status'   => 'active',
            'is_admin' => false,
        ]);

        $response = $this->actingAs($tenant)->getJson('/api/admin/users');
        $response->assertStatus(403);
    }

    /** 3. Tenant requests another tenant's resource -> Access denied */
    public function test_tenant_cannot_access_another_tenants_domain(): void
    {
        $tenant1 = User::factory()->create(['status' => 'active', 'is_admin' => false]);
        $tenant2 = User::factory()->create(['status' => 'active', 'is_admin' => false]);

        $domain2 = Domain::create([
            'user_id'      => $tenant2->id,
            'domain_name'  => 'tenant2.example.test',
            'status'       => 'active',
            'mx_verified'  => true,
            'spf_verified' => true,
        ]);

        $response = $this->actingAs($tenant1)->getJson("/api/domains/{$domain2->id}");
        $response->assertStatus(403);
    }

    /** 4. Suspended tenant is denied access to protected endpoints */
    public function test_suspended_tenant_is_denied_protected_endpoints(): void
    {
        $suspendedTenant = User::factory()->create([
            'status'   => 'suspended',
            'is_admin' => false,
        ]);

        $response = $this->actingAs($suspendedTenant)->getJson('/api/domains');
        $response->assertStatus(403);
    }

    /** 5. Tenant without a plan attempts quota-controlled sending -> Fail-closed quota rejection */
    public function test_tenant_without_plan_fails_closed_in_outbound_quota_service(): void
    {
        $quotaService = app(OutboundQuotaService::class);
        $user = User::factory()->create(['plan_id' => null]);
        $domain = Domain::create([
            'user_id'     => $user->id,
            'domain_name' => 'noplan.example.test',
            'status'      => 'active',
        ]);
        $mailbox = Mailbox::create([
            'domain_id'  => $domain->id,
            'local_part' => 'sender',
            'email'      => 'sender@noplan.example.test',
            'password'   => 'hash',
            'is_active'  => true,
        ]);

        $result = $quotaService->consume($user, $mailbox, 1);
        $this->assertSame('REJECTED_QUOTA', $result['status']);
    }

    /** 6. Cancelled invoice receives a payment callback -> Rejected; no subscription reactivation */
    public function test_cancelled_invoice_rejects_payment_callback(): void
    {
        $plan = $this->createTestPlan('starter-cancelled');

        $user = User::factory()->create([
            'plan_id'         => $plan->id,
            'status'          => 'suspended',
            'plan_expires_at' => Carbon::now()->subDays(5),
            'is_admin'        => false,
        ]);

        $invoice = Invoice::create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'invoice_number' => 'INV-TEST-CANCELLED',
            'billing_cycle'  => 'monthly',
            'subtotal'       => 199.00,
            'tax'            => 0.00,
            'total'          => 199.00,
            'currency'       => 'BDT',
            'status'         => 'cancelled',
            'due_date'       => Carbon::now()->addDays(7),
            'payment_gateway'=> 'sslcommerz',
            'transaction_id' => 'TXN-CANCELLED-123',
        ]);

        $billingService = app(BillingService::class);
        $billingService->markInvoicePaid($invoice, ['tran_id' => 'TXN-CANCELLED-123']);

        $invoice->refresh();
        $this->assertSame('cancelled', $invoice->status);
        $user->refresh();
        $this->assertSame('suspended', $user->status);
    }

    /** 7. Duplicate successful payment callback -> Idempotent processing */
    public function test_duplicate_successful_payment_callback_is_idempotent(): void
    {
        $plan = $this->createTestPlan('starter-dup');

        $user = User::factory()->create([
            'plan_id'         => $plan->id,
            'status'          => 'suspended',
            'plan_expires_at' => Carbon::now()->subDays(5),
            'is_admin'        => false,
        ]);

        $invoice = Invoice::create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'invoice_number' => 'INV-TEST-DUP',
            'billing_cycle'  => 'monthly',
            'subtotal'       => 199.00,
            'tax'            => 0.00,
            'total'          => 199.00,
            'currency'       => 'BDT',
            'status'         => 'pending',
            'due_date'       => Carbon::now()->addDays(7),
            'payment_gateway'=> 'sslcommerz',
            'transaction_id' => 'TXN-DUP-123',
        ]);

        $billingService = app(BillingService::class);

        // First callback
        $billingService->markInvoicePaid($invoice, ['tran_id' => 'TXN-DUP-123']);
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $firstPaidAt = $invoice->paid_at;

        // Duplicate callback
        $billingService->markInvoicePaid($invoice, ['tran_id' => 'TXN-DUP-123']);
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals($firstPaidAt->timestamp, $invoice->paid_at->timestamp);
    }

    /** 8. Expired tenant is activated through admin API -> Rejected (HTTP 422) */
    public function test_admin_api_rejects_activating_expired_tenant(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'status' => 'active']);
        $superAdmin->assignRole(RoleEnum::SUPER_ADMIN->value);

        $expiredCustomer = User::factory()->create([
            'is_admin'        => false,
            'status'          => 'suspended',
            'plan_expires_at' => Carbon::now()->subDays(5),
        ]);

        $response = $this->actingAs($superAdmin)->postJson("/api/admin/users/{$expiredCustomer->id}/activate");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot activate tenant with an expired subscription. Tenant must renew subscription or have plan expiration extended.');
    }

    /** 9. Unauthorized administrator lacks permission to manage plans -> Denied (HTTP 403) */
    public function test_unauthorized_administrator_cannot_manage_plans(): void
    {
        Permission::firstOrCreate(['name' => 'admin.plans.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'admin.plans.manage', 'guard_name' => 'web']);

        $customerSupport = User::factory()->create(['is_admin' => true, 'status' => 'active']);
        $customerSupport->assignRole(RoleEnum::CUSTOMER_SUPPORT->value);

        $response = $this->actingAs($customerSupport)->postJson('/api/admin/plans', [
            'name'                              => 'Unauthorized Plan',
            'slug'                              => 'unauth-plan',
            'max_domains'                       => 1,
            'max_mailboxes_per_domain'          => 5,
            'storage_mb_per_mailbox'            => 1024,
            'max_aliases_per_domain'            => 5,
            'daily_outbound_recipients'         => 100,
            'mailbox_daily_outbound_recipients' => 50,
            'price_monthly'                     => 50,
            'price_yearly'                      => 500,
        ]);

        // Customer support lacks admin.plans.manage permission
        $response->assertStatus(403);
    }

    /** 10. Audit log mutation endpoint is requested -> HTTP 405 (Method Not Allowed) */
    public function test_audit_log_mutation_endpoints_return_method_not_allowed_405(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'status' => 'active']);
        $superAdmin->assignRole(RoleEnum::SUPER_ADMIN->value);

        // POST /api/admin/audit-logs
        $responsePost = $this->actingAs($superAdmin)->postJson('/api/admin/audit-logs', [
            'action' => 'malicious.inject',
        ]);
        $responsePost->assertStatus(405);

        // DELETE /api/admin/audit-logs/1
        $responseDelete = $this->actingAs($superAdmin)->deleteJson('/api/admin/audit-logs/1');
        $responseDelete->assertStatus(405);
    }

    /** 11. Backup is decrypted with wrong key -> Authentication failure; no valid partial output */
    public function test_backup_decryption_with_wrong_key_fails_closed(): void
    {
        $encryptionService = app(BackupEncryptionService::class);
        $tempSource = tempnam(sys_get_temp_dir(), 'test_src_');
        $tempEncrypted = tempnam(sys_get_temp_dir(), 'test_enc_');
        $tempDecrypted = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_dec_' . uniqid() . '.txt';

        file_put_contents($tempSource, 'Confidential Database Backup SQL Payload');

        $correctKey = 'correct-master-backup-secret-key-32b!';
        $wrongKey   = 'wrong-invalid-backup-secret-key-32b!!';

        $encryptionService->encryptFile($tempSource, $tempEncrypted, $correctKey);

        $failed = false;
        try {
            $encryptionService->decryptFile($tempEncrypted, $tempDecrypted, $wrongKey);
        } catch (\Throwable $e) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Decryption with wrong key must throw an authentication failure exception.');
        // Partial output must not exist
        $this->assertFileDoesNotExist($tempDecrypted);

        @unlink($tempSource);
        @unlink($tempEncrypted);
    }

    /** 12. Backup manifest is tampered with -> HMAC verification failure */
    public function test_backup_manifest_tampering_fails_hmac_verification(): void
    {
        $encryptionService = app(BackupEncryptionService::class);
        $masterKey = 'master-secret-key-for-hmac-test-32b!';

        $manifestData = [
            'archive'    => 'db_backup_20261016.tar.gz.enc',
            'cipher'     => 'aes-256-gcm',
            'sha256'     => hash('sha256', 'original_content'),
            'size_bytes' => 1024,
            'created_at' => Carbon::now()->toIso8601String(),
        ];

        $hmac = $encryptionService->computeManifestHmac($manifestData, $masterKey);
        $manifestData['manifest_hmac'] = $hmac;

        // Verify valid HMAC
        $this->assertTrue($encryptionService->verifyManifestHmac($manifestData, $masterKey));

        // Tamper with manifest data
        $manifestData['size_bytes'] = 2048;

        // Verify tampered HMAC fails
        $this->assertFalse($encryptionService->verifyManifestHmac($manifestData, $masterKey));
    }

    /** 13. Failed login includes a password -> Password must not appear in audit records or logs */
    public function test_failed_login_omits_password_from_audit_records(): void
    {
        $auditService = app(AuditService::class);

        $log = $auditService->record(
            action: 'auth.failed',
            entityType: 'User',
            entityId: null,
            before: null,
            after: [
                'attempted_email' => 'victim@example.test',
                'password'        => 'SuperSecretPlainTextPassword123!',
                'raw_credentials' => ['password' => 'AnotherSecret123!'],
            ],
            reason: 'Failed login attempt'
        );

        $this->assertNotNull($log);
        $this->assertSame('victim@example.test', $log->after_state['attempted_email']);
        $this->assertSame('[REDACTED]', $log->after_state['password']);
        $this->assertSame('[REDACTED]', $log->after_state['raw_credentials']);
        $this->assertStringNotContainsString('SuperSecretPlainTextPassword123!', json_encode($log->after_state));
    }

    /** 14. SeedAuditDemoCommand safety guards refuse unapproved environment and remote host */
    public function test_seed_audit_demo_command_safety_guards(): void
    {
        // When in testing environment, command must refuse execution
        $exitCode = Artisan::call('emailsaas:seed-audit-demo', ['--dry-run' => true]);
        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString("strictly restricted to 'local' environment", $output);
    }
}
