<?php

namespace Tests\Feature;

use App\Console\Commands\SendSubscriptionExpiryRemindersCommand;
use App\Enums\RoleEnum;
use App\Models\AbuseIncident;
use App\Models\Invoice;
use App\Models\MailDeliveryRecord;
use App\Models\Plan;
use App\Models\User;
use App\Services\Abuse\AbuseIncidentService;
use App\Services\BillingService;
use App\Services\MailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransactionalMailDispatchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail_delivery.dry_run' => true]);

        // Seed basic admin roles
        foreach (RoleEnum::adminRoles() as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }
    }

    protected function createPlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name'                     => 'Starter Plan',
            'slug'                     => 'starter-' . uniqid(),
            'max_domains'              => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox'   => 1024,
            'max_aliases_per_domain'   => 10,
            'price_monthly'            => 1000,
            'price_yearly'             => 10000,
        ], $overrides));
    }

    // ─── 1. USER REGISTRATION & EMAIL VERIFICATION ─────────────────────────────

    public function test_user_registration_enqueues_account_verification_mail(): void
    {
        $payload = [
            'name'                  => 'Alice Tenant',
            'email'                 => 'alice@example.com',
            'password'              => 'SecretPassword123!',
            'password_confirmation' => 'SecretPassword123!',
            'company_name'          => 'Alice Corp',
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertStatus(201);
        $user = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($user);

        // Verify Mail Delivery Record was created
        $mailRecord = MailDeliveryRecord::where('recipient_email', 'alice@example.com')
            ->where('message_type', 'account_verification')
            ->first();

        $this->assertNotNull($mailRecord, 'Account verification mail was not enqueued.');
        $this->assertEquals($user->id, $mailRecord->tenant_id);
        $this->assertEquals("registration_verification:user_{$user->id}", $mailRecord->idempotency_key);
        $this->assertStringContainsString('Verify your EmailSaaS account', $mailRecord->subject);
        $this->assertNotEmpty($mailRecord->payload['verification_url']);

        // Invariant: verification URL must contain signed, expiring query parameters
        $url = $mailRecord->payload['verification_url'];
        $this->assertStringContainsString("id={$user->id}", $url);
        $this->assertStringContainsString("hash=", $url);
        $this->assertStringContainsString("expires=", $url);
        $this->assertStringContainsString("signature=", $url);
    }

    public function test_user_registration_succeeds_even_if_mail_enqueue_fails(): void
    {
        // Mock MailDeliveryService to simulate transient infrastructure failure
        $mock = $this->mock(MailDeliveryService::class);
        $mock->shouldReceive('enqueue')
            ->andThrow(new \RuntimeException('Transient Redis outage on queue dispatch'));

        $payload = [
            'name'                  => 'Bob FailSafe',
            'email'                 => 'bob@example.com',
            'password'              => 'SecretPassword123!',
            'password_confirmation' => 'SecretPassword123!',
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        // Registration must NOT fail
        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'bob@example.com']);
    }

    public function test_email_verification_with_valid_signed_parameters_succeeds(): void
    {
        $user = User::factory()->create([
            'email'             => 'verify-valid@example.com',
            'email_verified_at' => null,
        ]);

        $expires = now()->addHour()->timestamp;
        $hash = sha1($user->email);
        $signature = hash_hmac('sha256', "id={$user->id}&hash={$hash}&expires={$expires}", (string) config('app.key'));

        $response = $this->postJson('/api/auth/verify-email', [
            'id'        => $user->id,
            'hash'      => $hash,
            'expires'   => $expires,
            'signature' => $signature,
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_email_verification_rejects_expired_verification_link(): void
    {
        $user = User::factory()->create([
            'email'             => 'verify-expired@example.com',
            'email_verified_at' => null,
        ]);

        // Expired in the past
        $expires = now()->subMinutes(10)->timestamp;
        $hash = sha1($user->email);
        $signature = hash_hmac('sha256', "id={$user->id}&hash={$hash}&expires={$expires}", (string) config('app.key'));

        $response = $this->postJson('/api/auth/verify-email', [
            'id'        => $user->id,
            'hash'      => $hash,
            'expires'   => $expires,
            'signature' => $signature,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Verification link has expired.']);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_verification_rejects_tampered_signature(): void
    {
        $user = User::factory()->create([
            'email'             => 'verify-tampered@example.com',
            'email_verified_at' => null,
        ]);

        $expires = now()->addHour()->timestamp;
        $hash = sha1($user->email);

        $response = $this->postJson('/api/auth/verify-email', [
            'id'        => $user->id,
            'hash'      => $hash,
            'expires'   => $expires,
            'signature' => 'forged-or-tampered-signature-12345',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Invalid or tampered verification signature.']);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_verification_rejects_modified_user_id_or_hash(): void
    {
        $userA = User::factory()->create(['email' => 'usera@example.com', 'email_verified_at' => null]);
        $userB = User::factory()->create(['email' => 'userb@example.com', 'email_verified_at' => null]);

        $expires = now()->addHour()->timestamp;
        $hashA = sha1($userA->email);
        $signatureA = hash_hmac('sha256', "id={$userA->id}&hash={$hashA}&expires={$expires}", (string) config('app.key'));

        // Attempt to apply User A's signature to User B
        $response = $this->postJson('/api/auth/verify-email', [
            'id'        => $userB->id,
            'hash'      => $hashA,
            'expires'   => $expires,
            'signature' => $signatureA,
        ]);

        $response->assertStatus(400);
        $this->assertNull($userB->fresh()->email_verified_at);
    }

    public function test_email_verification_is_idempotent_for_already_verified_account(): void
    {
        $user = User::factory()->create([
            'email'             => 'already-verified@example.com',
            'email_verified_at' => now()->subDay(),
        ]);

        $originalVerifiedAt = $user->email_verified_at;
        $expires = now()->addHour()->timestamp;
        $hash = sha1($user->email);
        $signature = hash_hmac('sha256', "id={$user->id}&hash={$hash}&expires={$expires}", (string) config('app.key'));

        $response = $this->postJson('/api/auth/verify-email', [
            'id'        => $user->id,
            'hash'      => $hash,
            'expires'   => $expires,
            'signature' => $signature,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Email already verified.']);
        $this->assertEquals($originalVerifiedAt->toDateTimeString(), $user->fresh()->email_verified_at->toDateTimeString());
    }

    public function test_resend_verification_enqueues_email_and_prevents_enumeration(): void
    {
        $unverified = User::factory()->create([
            'email'             => 'unverified@example.com',
            'email_verified_at' => null,
        ]);

        $verified = User::factory()->create([
            'email'             => 'verified@example.com',
            'email_verified_at' => now(),
        ]);

        // 1. Unverified user receives verification email
        $res1 = $this->postJson('/api/auth/resend-verification', ['email' => 'unverified@example.com']);
        $res1->assertStatus(200);
        $this->assertDatabaseHas('mail_deliveries', [
            'recipient_email' => 'unverified@example.com',
            'message_type'    => 'account_verification',
        ]);

        // 2. Already verified user returns generic 200 without enqueuing
        $res2 = $this->postJson('/api/auth/resend-verification', ['email' => 'verified@example.com']);
        $res2->assertStatus(200);
        $this->assertDatabaseMissing('mail_deliveries', [
            'recipient_email' => 'verified@example.com',
        ]);

        // 3. Nonexistent email returns generic 200 without enqueuing (anti-enumeration)
        $res3 = $this->postJson('/api/auth/resend-verification', ['email' => 'ghost@example.com']);
        $res3->assertStatus(200);
        $this->assertDatabaseMissing('mail_deliveries', [
            'recipient_email' => 'ghost@example.com',
        ]);
    }

    // ─── 2. PASSWORD RESET FLOW & TOKEN SECURITY ───────────────────────────────

    public function test_forgot_password_enqueues_reset_email_with_safe_url_and_scrubs_raw_token(): void
    {
        $user = User::factory()->create([
            'email' => 'carol@example.com',
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'carol@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message']);

        // Check MailDeliveryRecord
        $record = MailDeliveryRecord::where('recipient_email', 'carol@example.com')
            ->where('message_type', 'password_reset')
            ->first();

        $this->assertNotNull($record, 'Password reset mail was not enqueued.');
        $this->assertEquals($user->id, $record->tenant_id);
        $this->assertStringContainsString('reset-password?token=', $record->payload['reset_url']);

        // Invariant: raw 'token' key must NEVER be stored unredacted in database payload
        $this->assertArrayNotHasKey('token', $record->payload);
    }

    public function test_password_reset_token_is_encrypted_at_rest_and_never_persisted_in_plaintext_db_payload(): void
    {
        $user = User::factory()->create([
            'email' => 'encrypted-token-test@example.com',
        ]);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'encrypted-token-test@example.com',
        ])->assertStatus(200);

        $record = MailDeliveryRecord::where('recipient_email', 'encrypted-token-test@example.com')->first();
        $this->assertNotNull($record);

        // Fetch RAW un-casted database value directly from MySQL/SQLite
        $rawDatabasePayload = DB::table('mail_deliveries')->where('id', $record->id)->value('payload');

        // Extract the plain token from the decrypted model attribute
        preg_match('/token=([^&]+)/', $record->payload['reset_url'], $matches);
        $this->assertNotEmpty($matches[1]);
        $plainToken = $matches[1];

        // INVARIANT: Raw database payload MUST NOT contain the plaintext token!
        $this->assertStringNotContainsString($plainToken, $rawDatabasePayload, 'VULNERABILITY: Plaintext reset token is exposed in database storage!');

        // INVARIANT: Raw database payload MUST NOT contain plaintext reset URL
        $this->assertStringNotContainsString('reset-password', $rawDatabasePayload, 'VULNERABILITY: Plaintext reset URL is exposed in database storage!');

        // Model attribute decryption works seamlessly in-memory
        $this->assertIsArray($record->payload);
        $this->assertStringContainsString("token={$plainToken}", $record->payload['reset_url']);
    }

    public function test_scrub_payload_recursively_redacts_nested_secret_keys(): void
    {
        $service = app(MailDeliveryService::class);

        $dirtyPayload = [
            'public_info'      => 'Safe Info',
            'api_token'        => 'secret_api_token_123',
            'security_context' => ['user_password' => 'secret_pass_456'],
            'deep'             => [
                'nested' => [
                    'private_key'   => 'secret_privkey_789',
                    'session_token' => 'secret_token_000',
                ],
            ],
        ];

        $cleaned = $service->scrubPayload($dirtyPayload);

        $this->assertEquals('Safe Info', $cleaned['public_info']);
        $this->assertEquals('[REDACTED]', $cleaned['api_token']);
        $this->assertEquals('[REDACTED]', $cleaned['security_context']['user_password']);
        $this->assertEquals('[REDACTED]', $cleaned['deep']['nested']['private_key']);
        $this->assertEquals('[REDACTED]', $cleaned['deep']['nested']['session_token']);
    }

    public function test_password_reset_event_is_dispatched_exactly_once_on_success_and_never_on_failure(): void
    {
        Event::fake([\Illuminate\Auth\Events\PasswordReset::class]);

        $user = User::factory()->create([
            'email'    => 'event-test@example.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        $token = Password::broker()->createToken($user);

        // 1. Invalid reset attempt: event must NOT be dispatched
        $this->postJson('/api/auth/reset-password', [
            'email'                 => 'event-test@example.com',
            'token'                 => 'invalid-token',
            'password'              => 'NewValidPassword123!',
            'password_confirmation' => 'NewValidPassword123!',
        ])->assertStatus(400);

        Event::assertNotDispatched(\Illuminate\Auth\Events\PasswordReset::class);

        // 2. Successful reset attempt: event must be dispatched EXACTLY once
        $this->postJson('/api/auth/reset-password', [
            'email'                 => 'event-test@example.com',
            'token'                 => $token,
            'password'              => 'NewValidPassword123!',
            'password_confirmation' => 'NewValidPassword123!',
        ])->assertStatus(200);

        Event::assertDispatched(\Illuminate\Auth\Events\PasswordReset::class, 1);

        // 3. Replay with the same token: token is already consumed/deleted, must fail and NOT dispatch again
        $this->postJson('/api/auth/reset-password', [
            'email'                 => 'event-test@example.com',
            'token'                 => $token,
            'password'              => 'AnotherPassword456!',
            'password_confirmation' => 'AnotherPassword456!',
        ])->assertStatus(400);

        // Still dispatched only once across the entire test
        Event::assertDispatched(\Illuminate\Auth\Events\PasswordReset::class, 1);
    }

    public function test_forgot_password_for_nonexistent_or_suspended_user_does_not_enqueue_mail(): void
    {
        // Nonexistent user returns generic 200 without enqueuing
        $responseNonexistent = $this->postJson('/api/auth/forgot-password', [
            'email' => 'doesnotexist@example.com',
        ]);
        $responseNonexistent->assertStatus(200);
        $this->assertDatabaseMissing('mail_deliveries', ['recipient_email' => 'doesnotexist@example.com']);

        // Suspended user returns generic 200 without enqueuing
        $suspendedUser = User::factory()->create([
            'email'  => 'suspended@example.com',
            'status' => 'suspended',
        ]);

        $responseSuspended = $this->postJson('/api/auth/forgot-password', [
            'email' => 'suspended@example.com',
        ]);
        $responseSuspended->assertStatus(200);
        $this->assertDatabaseMissing('mail_deliveries', ['recipient_email' => 'suspended@example.com']);
    }

    public function test_reset_password_updates_password_with_valid_token_and_rejects_invalid_token(): void
    {
        $user = User::factory()->create([
            'email'    => 'dave@example.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        $token = Password::broker()->createToken($user);

        // Invalid token rejected
        $failResponse = $this->postJson('/api/auth/reset-password', [
            'email'                 => 'dave@example.com',
            'token'                 => 'invalid-token-123',
            'password'              => 'NewStrongPassword456!',
            'password_confirmation' => 'NewStrongPassword456!',
        ]);
        $failResponse->assertStatus(400);

        // Valid token succeeds
        $successResponse = $this->postJson('/api/auth/reset-password', [
            'email'                 => 'dave@example.com',
            'token'                 => $token,
            'password'              => 'NewStrongPassword456!',
            'password_confirmation' => 'NewStrongPassword456!',
        ]);
        $successResponse->assertStatus(200);

        $this->assertTrue(Hash::check('NewStrongPassword456!', $user->fresh()->password));
    }

    // ─── 3. BILLING PAYMENT & SUBSCRIPTION RENEWAL ────────────────────────────

    public function test_mark_invoice_paid_enqueues_billing_invoice_mail_on_new_subscription(): void
    {
        $plan = $this->createPlan([
            'name'          => 'Pro Plan',
            'price_monthly' => 2000,
        ]);

        $user = User::factory()->create([
            'email'           => 'eric@example.com',
            'status'          => 'pending',
            'plan_expires_at' => null,
        ]);

        $invoice = Invoice::create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'invoice_number' => 'INV-TEST-001',
            'billing_cycle'  => 'monthly',
            'subtotal'       => 2000,
            'tax'            => 0,
            'total'          => 2000,
            'currency'       => 'BDT',
            'status'         => 'pending',
            'due_date'       => now()->addDays(3),
        ]);

        $billingService = app(BillingService::class);
        $billingService->markInvoicePaid($invoice, ['bank_tran_id' => 'BANK-TX-100']);

        // Check MailDeliveryRecord
        $record = MailDeliveryRecord::where('recipient_email', 'eric@example.com')
            ->where('message_type', 'billing_invoice')
            ->first();

        $this->assertNotNull($record, 'Billing invoice mail was not enqueued on invoice payment.');
        $this->assertEquals($user->id, $record->tenant_id);
        $this->assertEquals("billing_paid:invoice_{$invoice->id}", $record->idempotency_key);
        $this->assertEquals('INV-TEST-001', $record->payload['invoice_number']);
        $this->assertEquals(2000.0, (float) $record->payload['amount']);
        $this->assertEquals('BDT', $record->payload['currency']);
    }

    public function test_mark_invoice_paid_enqueues_subscription_renewal_mail_on_existing_subscription(): void
    {
        $plan = $this->createPlan([
            'name'          => 'Enterprise Plan',
            'price_monthly' => 5000,
        ]);

        $user = User::factory()->create([
            'email'           => 'fiona@example.com',
            'status'          => 'active',
            'plan_expires_at' => now()->addDays(5),
        ]);

        $invoice = Invoice::create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'invoice_number' => 'INV-TEST-002',
            'billing_cycle'  => 'monthly',
            'subtotal'       => 5000,
            'tax'            => 0,
            'total'          => 5000,
            'currency'       => 'BDT',
            'status'         => 'pending',
            'due_date'       => now()->addDays(3),
        ]);

        $billingService = app(BillingService::class);
        $billingService->markInvoicePaid($invoice, ['bank_tran_id' => 'BANK-TX-200']);

        // Check MailDeliveryRecord for subscription_renewal
        $record = MailDeliveryRecord::where('recipient_email', 'fiona@example.com')
            ->where('message_type', 'subscription_renewal')
            ->first();

        $this->assertNotNull($record, 'Subscription renewal mail was not enqueued.');
        $this->assertEquals($user->id, $record->tenant_id);
        $this->assertEquals("billing_renewal:invoice_{$invoice->id}", $record->idempotency_key);
        $this->assertEquals('INV-TEST-002', $record->payload['invoice_number']);
        $this->assertEquals(5000.0, (float) $record->payload['amount']);
    }

    public function test_billing_payment_is_idempotent_and_does_not_duplicate_emails(): void
    {
        $plan = $this->createPlan(['price_monthly' => 1000]);
        $user = User::factory()->create(['email' => 'george@example.com', 'status' => 'pending']);

        $invoice = Invoice::create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'invoice_number' => 'INV-IDEMP-001',
            'billing_cycle'  => 'monthly',
            'subtotal'       => 1000,
            'tax'            => 0,
            'total'          => 1000,
            'currency'       => 'BDT',
            'status'         => 'pending',
            'due_date'       => now()->addDays(3),
        ]);

        $billingService = app(BillingService::class);

        // Call 1
        $billingService->markInvoicePaid($invoice, ['bank_tran_id' => 'TX-1']);
        $countAfterFirst = MailDeliveryRecord::where('recipient_email', 'george@example.com')->count();
        $this->assertEquals(1, $countAfterFirst);

        // Call 2 (Duplicate Webhook replay)
        $billingService->markInvoicePaid($invoice, ['bank_tran_id' => 'TX-1']);
        $countAfterSecond = MailDeliveryRecord::where('recipient_email', 'george@example.com')->count();
        $this->assertEquals(1, $countAfterSecond, 'Duplicate webhook replay produced duplicate mail deliveries.');
    }

    // ─── 4. SUBSCRIPTION EXPIRY REMINDERS ──────────────────────────────────────

    public function test_expiry_reminder_command_dispatches_reminders_to_approaching_tenants(): void
    {
        $plan = $this->createPlan(['name' => 'Starter Plan']);

        // Tenant A: expires in exactly 7 days
        $tenantA = User::factory()->create([
            'email'           => 'tenant-a@example.com',
            'status'          => 'active',
            'plan_id'         => $plan->id,
            'plan_expires_at' => now()->addDays(7)->startOfHour(),
            'is_admin'        => false,
        ]);

        // Tenant B: expires in 30 days (should NOT get reminder)
        $tenantB = User::factory()->create([
            'email'           => 'tenant-b@example.com',
            'status'          => 'active',
            'plan_id'         => $plan->id,
            'plan_expires_at' => now()->addDays(30),
            'is_admin'        => false,
        ]);

        $this->artisan('subscription:send-expiry-reminders', ['--days' => '7,3,1'])
            ->assertExitCode(0);

        // Tenant A should have received a reminder
        $this->assertDatabaseHas('mail_deliveries', [
            'recipient_email' => 'tenant-a@example.com',
            'message_type'    => 'subscription_expiry_reminder',
        ]);

        // Tenant B should NOT have received a reminder
        $this->assertDatabaseMissing('mail_deliveries', [
            'recipient_email' => 'tenant-b@example.com',
        ]);
    }

    public function test_expiry_reminder_command_is_idempotent_and_dry_run_does_not_mutate(): void
    {
        $plan = $this->createPlan(['name' => 'Starter Plan']);

        $tenant = User::factory()->create([
            'email'           => 'idemp-tenant@example.com',
            'status'          => 'active',
            'plan_id'         => $plan->id,
            'plan_expires_at' => now()->addDays(3)->startOfHour(),
            'is_admin'        => false,
        ]);

        // 1. Dry run should NOT create any records
        $this->artisan('subscription:send-expiry-reminders', ['--days' => '7,3,1', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('mail_deliveries', [
            'recipient_email' => 'idemp-tenant@example.com',
        ]);

        // 2. Real run creates exactly 1 record
        $this->artisan('subscription:send-expiry-reminders', ['--days' => '7,3,1'])
            ->assertExitCode(0);

        $count = MailDeliveryRecord::where('recipient_email', 'idemp-tenant@example.com')->count();
        $this->assertEquals(1, $count);

        // 3. Second run does not duplicate
        $this->artisan('subscription:send-expiry-reminders', ['--days' => '7,3,1'])
            ->assertExitCode(0);

        $this->assertEquals(1, MailDeliveryRecord::where('recipient_email', 'idemp-tenant@example.com')->count());
    }

    // ─── 5. SECURITY & ABUSE INCIDENTS ────────────────────────────────────────

    public function test_critical_abuse_incident_dispatches_security_alert_mail_omitting_secrets(): void
    {
        $user = User::factory()->create([
            'email'  => 'victim@example.com',
            'status' => 'active',
        ]);

        $abuseService = app(AbuseIncidentService::class);

        $incident = $abuseService->record([
            'tenant_id'        => $user->id,
            'incident_type'    => 'consecutive_hard_bounces',
            'severity'         => 'critical',
            'summary'          => 'Exceeded 15 consecutive hard bounces on recipient list.',
            'evidence'         => [
                'raw_secret_key' => 'super_secret_token_12345',
                'internal_path'  => '/etc/postfix/secret.conf',
            ],
            'idempotency_key'  => 'incident_test_123',
        ]);

        $this->assertNotNull($incident);

        // Check MailDeliveryRecord
        $record = MailDeliveryRecord::where('recipient_email', 'victim@example.com')
            ->where('message_type', 'security_alert')
            ->first();

        $this->assertNotNull($record, 'Security alert mail was not dispatched for critical incident.');
        $this->assertEquals($user->id, $record->tenant_id);
        $this->assertEquals("security_alert:incident_{$incident->uuid}", $record->idempotency_key);
        $this->assertEquals('critical', $record->payload['severity']);

        // Invariant: payload must NEVER contain raw secrets or private tokens
        $this->assertArrayNotHasKey('raw_secret_key', $record->payload);
        $this->assertArrayNotHasKey('internal_path', $record->payload);
    }

    public function test_low_or_warning_abuse_incident_does_not_dispatch_security_alert_mail(): void
    {
        $user = User::factory()->create([
            'email'  => 'calm@example.com',
            'status' => 'active',
        ]);

        $abuseService = app(AbuseIncidentService::class);

        $incident = $abuseService->record([
            'tenant_id'       => $user->id,
            'incident_type'   => 'soft_bounce_elevated',
            'severity'        => 'warning',
            'summary'         => 'Slight increase in soft deferrals observed.',
            'idempotency_key' => 'incident_warning_001',
        ]);

        $this->assertNotNull($incident);

        // Mail should NOT be enqueued for warning severity
        $this->assertDatabaseMissing('mail_deliveries', [
            'recipient_email' => 'calm@example.com',
            'message_type'    => 'security_alert',
        ]);
    }

    // ─── 6. ACCOUNT AUTO-SUSPENSION MAIL DISPATCH ─────────────────────────────

    public function test_auto_suspend_expired_tenants_enqueues_account_suspension_mail(): void
    {
        $user = User::factory()->create([
            'email'           => 'expired-user@example.com',
            'status'          => 'active',
            'plan_expires_at' => now()->subHours(2),
            'is_admin'        => false,
        ]);

        $this->artisan('tenant:suspend-expired')
            ->assertExitCode(0);

        $this->assertEquals('suspended', $user->fresh()->status);

        $record = MailDeliveryRecord::where('recipient_email', 'expired-user@example.com')
            ->where('message_type', 'security_alert')
            ->first();

        $this->assertNotNull($record, 'Suspension notification mail was not enqueued.');
        $this->assertEquals($user->id, $record->tenant_id);
        $this->assertEquals('critical', $record->payload['severity']);
    }

    // ─── 7. TRANSACTION ROLLBACK ISOLATION & FAIL-SAFE GUARANTEES ──────────────

    public function test_aborted_transaction_never_executes_after_commit_mail_dispatch(): void
    {
        $tenant = User::factory()->create(['email' => 'rollback-tenant@example.com']);

        try {
            DB::transaction(function () use ($tenant) {
                $tenant->update(['status' => 'suspended']);

                DB::afterCommit(function () use ($tenant) {
                    app(MailDeliveryService::class)->enqueue(
                        tenantId: $tenant->id,
                        recipientEmail: $tenant->email,
                        messageType: 'security_alert',
                        subject: 'Test Alert',
                        payload: ['alert_title' => 'Test', 'severity' => 'warning'],
                    );
                });

                // Abort the transaction
                throw new \Exception('Simulated database transaction failure');
            });
        } catch (\Exception $e) {
            // Expected
        }

        // 1. Database state rolled back
        $this->assertEquals('active', $tenant->fresh()->status);

        // 2. Mail Delivery record must NOT exist
        $this->assertDatabaseMissing('mail_deliveries', [
            'recipient_email' => 'rollback-tenant@example.com',
        ]);
    }

    public function test_mail_enqueue_failure_inside_after_commit_does_not_abort_committed_invoice(): void
    {
        $plan = $this->createPlan(['price_monthly' => 1500]);
        $user = User::factory()->create(['email' => 'failsafe-invoice@example.com', 'status' => 'pending']);

        $invoice = Invoice::create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'invoice_number' => 'INV-FAILSAFE-001',
            'billing_cycle'  => 'monthly',
            'subtotal'       => 1500,
            'tax'            => 0,
            'total'          => 1500,
            'currency'       => 'BDT',
            'status'         => 'pending',
            'due_date'       => now()->addDays(3),
        ]);

        // Mock MailDeliveryService to fail inside afterCommit
        $mock = $this->mock(MailDeliveryService::class);
        $mock->shouldReceive('enqueue')
            ->andThrow(new \RuntimeException('Mail queue worker connection refused'));

        $billingService = app(BillingService::class);
        $billingService->markInvoicePaid($invoice, ['bank_tran_id' => 'TX-FAILSAFE-1']);

        // Invariant: The invoice must remain paid and user activated despite mail failure
        $this->assertEquals('paid', $invoice->fresh()->status);
        $this->assertEquals('active', $user->fresh()->status);
    }
}
