<?php

namespace Tests\Feature;

use App\Exceptions\MailDelivery\InvalidPayloadException;
use App\Exceptions\MailDelivery\InvalidRecipientException;
use App\Exceptions\MailDelivery\PermanentDeliveryException;
use App\Exceptions\MailDelivery\TransientDeliveryException;
use App\Exceptions\MailDelivery\UnsupportedMessageTypeException;
use App\Jobs\ProcessMailDeliveryJob;
use App\Models\MailDeliveryRecord;
use App\Models\User;
use App\Services\Health\SystemHealthService;
use App\Services\MailDeliveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailDeliveryQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected User $otherTenant;
    protected User $admin;
    protected MailDeliveryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // Seed roles and permissions for tests
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);

        $this->tenant = User::factory()->create([
            'status' => 'active',
        ]);

        $this->otherTenant = User::factory()->create([
            'status' => 'active',
        ]);

        $this->admin = User::factory()->superAdmin()->create([
            'status' => 'active',
        ]);

        $this->service = app(MailDeliveryService::class);
    }

    public function test_dry_run_mode_enqueues_and_simulates_delivery_without_live_network(): void
    {
        Config::set('mail_delivery.dry_run', true);

        $record = $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'verify@example.com',
            messageType: 'account_verification',
            subject: 'Verify Your Email',
            payload: [
                'user_name' => 'Alice',
                'verification_url' => 'https://example.com/verify?token=xyz123',
            ]
        );

        $this->assertInstanceOf(MailDeliveryRecord::class, $record);
        $this->assertEquals($this->tenant->id, $record->tenant_id);
        $this->assertEquals('verify@example.com', $record->recipient_email);
        $this->assertEquals('account_verification', $record->message_type);
        $this->assertTrue($record->is_dry_run);

        // Process the job
        $job = new ProcessMailDeliveryJob($record->id);
        $job->handle();

        $refreshed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_SENT, $refreshed->status);
        $this->assertNotNull($refreshed->sent_at);
        $this->assertEquals(1, $refreshed->attempts);
        $this->assertNull($refreshed->last_error);
    }

    public function test_idempotent_enqueue_prevents_duplicate_deliveries(): void
    {
        Queue::fake();

        $idempotencyKey = 'idemp_key_step40_' . uniqid();

        $record1 = $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'tenant@example.com',
            messageType: 'billing_invoice',
            subject: 'Invoice #1001',
            payload: [
                'user_name' => 'Alice',
                'invoice_number' => 'INV-1001',
                'amount' => '$29.00',
            ],
            idempotencyKey: $idempotencyKey
        );

        $record2 = $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'tenant@example.com',
            messageType: 'billing_invoice',
            subject: 'Invoice #1001 Duplicate Attempt',
            payload: [
                'user_name' => 'Alice',
                'invoice_number' => 'INV-1001',
                'amount' => '$29.00',
            ],
            idempotencyKey: $idempotencyKey
        );

        $this->assertEquals($record1->id, $record2->id);
        $this->assertEquals(1, MailDeliveryRecord::where('idempotency_key', $idempotencyKey)->count());
        Queue::assertPushed(ProcessMailDeliveryJob::class, 1);
    }

    public function test_unsupported_message_type_throws_permanent_exception(): void
    {
        $this->expectException(UnsupportedMessageTypeException::class);

        $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'test@example.com',
            messageType: 'marketing_blast',
            subject: 'Spam Subject',
            payload: []
        );
    }

    public function test_invalid_recipient_email_throws_permanent_exception(): void
    {
        $this->expectException(InvalidRecipientException::class);

        $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'not-an-email',
            messageType: 'password_reset',
            subject: 'Reset Password',
            payload: [
                'user_name' => 'Bob',
                'reset_url' => 'https://example.com/reset?token=abc',
            ]
        );
    }

    public function test_missing_required_payload_fields_throws_invalid_payload_exception(): void
    {
        $this->expectException(InvalidPayloadException::class);

        // password_reset requires 'user_name' and 'reset_url'
        $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'valid@example.com',
            messageType: 'password_reset',
            subject: 'Reset Password',
            payload: [
                'user_name' => 'Bob',
                // missing reset_url
            ]
        );
    }

    public function test_sensitive_payload_fields_are_masked_on_enqueue(): void
    {
        $record = $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'secure@example.com',
            messageType: 'account_verification',
            subject: 'Account Verification',
            payload: [
                'user_name' => 'Charlie',
                'verification_url' => 'https://example.com/verify?token=plain_token_123',
                'password' => 'SuperSecret123!',
                'token' => 'raw_auth_token_secret',
            ]
        );

        $payload = $record->payload;
        $this->assertEquals('[REDACTED]', $payload['password']);
        $this->assertEquals('[REDACTED]', $payload['token']);
        $this->assertStringContainsString('plain_token_123', $payload['verification_url']);
    }

    public function test_permanent_delivery_failure_marks_record_failed_immediately_without_retry(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'reject@example.com',
            'message_type' => 'security_alert',
            'template_id' => 'security_alert',
            'subject' => 'Suspicious Activity',
            'payload' => ['user_name' => 'Dave', 'alert_details' => 'Failed logins'],
            'status' => MailDeliveryRecord::STATUS_QUEUED,
            'attempts' => 0,
            'max_attempts' => 3,
            'is_dry_run' => false,
        ]);

        $job = new ProcessMailDeliveryJob($record->id);
        $job->failed(new PermanentDeliveryException('Recipient mailbox permanently deleted by target MX.'));

        $refreshed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_FAILED, $refreshed->status);
        $this->assertNotNull($refreshed->failed_at);
        $this->assertStringContainsString('permanently deleted', $refreshed->last_error);
    }

    public function test_transient_failure_increments_attempts_and_releases_with_backoff(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'retry@example.com',
            'message_type' => 'security_alert',
            'template_id' => 'security_alert',
            'subject' => 'Alert',
            'payload' => ['user_name' => 'Eve', 'alert_details' => 'Login attempt'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 1,
            'max_attempts' => 3,
            'last_attempted_at' => now(),
            'is_dry_run' => false,
        ]);

        // Calculate backoff
        $backoff = $this->service->calculateBackoffSeconds(attempt: 1, baseBackoff: 60, maxBackoff: 900);
        $this->assertGreaterThanOrEqual(54, $backoff); // 60 * 0.9 = 54
        $this->assertLessThanOrEqual(72, $backoff);    // 60 * 1.2 = 72

        // Mark transient retry
        $record->markFailedAttempt('Temporary 421 Service unavailable', isTransient: true);

        $refreshed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_QUEUED, $refreshed->status);
        $this->assertEquals(2, $refreshed->attempts);
        $this->assertStringContainsString('Temporary 421', $refreshed->last_error);
        $this->assertNull($refreshed->failed_at);
    }

    public function test_exceeding_max_attempts_dead_letters_to_failed(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'exhaust@example.com',
            'message_type' => 'security_alert',
            'template_id' => 'security_alert',
            'subject' => 'Alert',
            'payload' => ['user_name' => 'Frank', 'alert_details' => 'Alert info'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 2, // will become 3 (= max_attempts)
            'max_attempts' => 3,
            'last_attempted_at' => now(),
            'is_dry_run' => false,
        ]);

        $record->markFailedAttempt('Persistent network timeout', isTransient: true);

        $refreshed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_FAILED, $refreshed->status);
        $this->assertEquals(3, $refreshed->attempts);
        $this->assertNotNull($refreshed->failed_at);
        $this->assertTrue($refreshed->isFailed());
        $this->assertTrue($refreshed->canRetry());
    }

    public function test_tenant_can_list_and_view_own_deliveries_via_api(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'alice@tenant.com',
            'message_type' => 'account_verification',
            'template_id' => 'account_verification',
            'subject' => 'Verify Email',
            'payload' => ['user_name' => 'Alice', 'verification_url' => 'https://example.com/v'],
            'status' => MailDeliveryRecord::STATUS_SENT,
            'attempts' => 1,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        $otherRecord = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->otherTenant->id,
            'recipient_email' => 'other@other.com',
            'message_type' => 'password_reset',
            'template_id' => 'password_reset',
            'subject' => 'Reset Password',
            'payload' => ['user_name' => 'Other', 'reset_url' => 'https://example.com/r'],
            'status' => MailDeliveryRecord::STATUS_SENT,
            'attempts' => 1,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        // Tenant lists their deliveries
        $response = $this->actingAs($this->tenant)
            ->getJson('/api/mail-deliveries');

        $response->assertStatus(200);
        $response->assertJsonFragment(['recipient_email' => 'alice@tenant.com']);
        $response->assertJsonMissing(['recipient_email' => 'other@other.com']);

        // Tenant views own record
        $detailResponse = $this->actingAs($this->tenant)
            ->getJson("/api/mail-deliveries/{$record->id}");

        $detailResponse->assertStatus(200);
        $detailResponse->assertJsonPath('data.id', $record->id);
        $detailResponse->assertJsonPath('data.recipient_email', 'alice@tenant.com');

        // Tenant attempts to view another tenant's record -> 404
        $forbiddenResponse = $this->actingAs($this->tenant)
            ->getJson("/api/mail-deliveries/{$otherRecord->id}");

        $forbiddenResponse->assertStatus(404);
    }

    public function test_admin_can_view_mail_queue_operational_status_via_api(): void
    {
        MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'admin_test@example.com',
            'message_type' => 'billing_invoice',
            'template_id' => 'billing_invoice',
            'subject' => 'Invoice #999',
            'payload' => ['user_name' => 'Test', 'invoice_number' => 'INV-999', 'amount' => '$10'],
            'status' => MailDeliveryRecord::STATUS_QUEUED,
            'attempts' => 0,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/system/mail-queue');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'dry_run_mode',
            'queue_driver',
            'metrics' => [
                'total_deliveries',
                'queued_count',
                'processing_count',
                'sent_count',
                'failed_count',
                'cancelled_count',
                'dead_letter_count',
                'retried_count',
                'stale_processing_count',
            ],
            'database_queue' => [
                'pending_jobs_table_count',
                'failed_jobs_table_count_24h',
            ],
            'note',
        ]);
    }

    public function test_admin_can_retry_failed_delivery_via_api(): void
    {
        Queue::fake();

        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'retry_me@example.com',
            'message_type' => 'account_verification',
            'template_id' => 'account_verification',
            'subject' => 'Verify',
            'payload' => ['user_name' => 'Retry', 'verification_url' => 'https://example.com/v'],
            'status' => MailDeliveryRecord::STATUS_FAILED,
            'attempts' => 3,
            'max_attempts' => 3,
            'failed_at' => now(),
            'last_error' => 'Connection timeout',
            'is_dry_run' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/system/mail-deliveries/{$record->id}/retry");

        $response->assertStatus(200);
        $response->assertJsonPath('delivery.status', 'queued');

        $refreshed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_QUEUED, $refreshed->status);
        $this->assertEquals(0, $refreshed->attempts);
        $this->assertNull($refreshed->failed_at);
        Queue::assertPushed(ProcessMailDeliveryJob::class, 1);
    }

    public function test_admin_can_cancel_queued_delivery_via_api(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'cancel_me@example.com',
            'message_type' => 'billing_invoice',
            'template_id' => 'billing_invoice',
            'subject' => 'Invoice',
            'payload' => ['user_name' => 'Cancel', 'invoice_number' => 'INV-1', 'amount' => '$1'],
            'status' => MailDeliveryRecord::STATUS_QUEUED,
            'attempts' => 0,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/system/mail-deliveries/{$record->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('delivery.status', 'cancelled');

        $refreshed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_CANCELLED, $refreshed->status);
        $this->assertNotNull($refreshed->cancelled_at);
    }

    public function test_stale_processing_recovery_command(): void
    {
        Queue::fake();

        $staleRecord = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'stale@example.com',
            'message_type' => 'account_verification',
            'template_id' => 'account_verification',
            'subject' => 'Stale Subject',
            'payload' => ['user_name' => 'Stale', 'verification_url' => 'https://example.com/v'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 1,
            'max_attempts' => 3,
            'last_attempted_at' => Carbon::now()->subMinutes(30),
            'is_dry_run' => true,
        ]);

        $this->artisan('mail:recover-stale', ['--stale-seconds' => 900])
            ->assertExitCode(0);

        $refreshed = $staleRecord->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_QUEUED, $refreshed->status);
        $this->assertStringContainsString('interrupted processing', $refreshed->last_error);
        Queue::assertPushed(ProcessMailDeliveryJob::class, 1);
    }

    public function test_system_health_service_includes_mail_delivery_status(): void
    {
        $healthService = app(SystemHealthService::class);
        $health = $healthService->getHealth();

        $this->assertArrayHasKey('mail_delivery', $health['components']);
        $this->assertContains($health['components']['mail_delivery']['status'], ['HEALTHY', 'DEGRADED']);
        $this->assertArrayHasKey('details', $health['components']['mail_delivery']);
        $this->assertArrayHasKey('dry_run_mode', $health['components']['mail_delivery']['details']);
    }
}
