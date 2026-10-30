<?php

namespace Tests\Feature;

use App\Exceptions\MailDelivery\InvalidDeliveryStateException;
use App\Exceptions\MailDelivery\InvalidPayloadException;
use App\Exceptions\MailDelivery\RateLimitExceededException;
use App\Jobs\ProcessMailDeliveryJob;
use App\Mail\TransactionalMail;
use App\Models\MailDeliveryRecord;
use App\Models\User;
use App\Services\MailDeliveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class MailDeliverySecurityAndConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected User $admin;
    protected MailDeliveryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clearResolvedInstances();

        // Seed roles and permissions for tests
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);

        $this->tenant = User::factory()->create([
            'status' => 'active',
        ]);

        $this->admin = User::factory()->superAdmin()->create([
            'status' => 'active',
        ]);

        $this->service = app(MailDeliveryService::class);
    }

    public function test_valid_state_transitions_follow_permitted_lifecycle(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'valid@example.com',
            'message_type' => 'account_verification',
            'template_id' => 'account_verification',
            'subject' => 'Verify',
            'payload' => ['user_name' => 'Alice', 'verification_url' => 'https://example.com/v'],
            'status' => MailDeliveryRecord::STATUS_QUEUED,
            'attempts' => 0,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        // queued -> processing
        $this->assertTrue($record->canTransitionTo(MailDeliveryRecord::STATUS_PROCESSING));
        $record->transitionTo(MailDeliveryRecord::STATUS_PROCESSING);
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $record->status);

        // processing -> sent
        $this->assertTrue($record->canTransitionTo(MailDeliveryRecord::STATUS_SENT));
        $record->transitionTo(MailDeliveryRecord::STATUS_SENT);
        $this->assertEquals(MailDeliveryRecord::STATUS_SENT, $record->status);
        $this->assertTrue($record->isSent());

        // sent has zero allowed transitions (terminal)
        $this->assertFalse($record->canTransitionTo(MailDeliveryRecord::STATUS_QUEUED));
        $this->assertFalse($record->canTransitionTo(MailDeliveryRecord::STATUS_PROCESSING));
        $this->assertFalse($record->canTransitionTo(MailDeliveryRecord::STATUS_FAILED));
        $this->assertFalse($record->canTransitionTo(MailDeliveryRecord::STATUS_CANCELLED));
    }

    public function test_invalid_state_transitions_throw_exception(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'terminal@example.com',
            'message_type' => 'billing_invoice',
            'template_id' => 'billing_invoice',
            'subject' => 'Invoice',
            'payload' => ['user_name' => 'Bob', 'invoice_number' => 'INV-1', 'amount' => '$50'],
            'status' => MailDeliveryRecord::STATUS_SENT,
            'sent_at' => now(),
            'attempts' => 1,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        $this->expectException(InvalidDeliveryStateException::class);
        $record->transitionTo(MailDeliveryRecord::STATUS_QUEUED);
    }

    public function test_admin_retry_on_in_flight_processing_record_is_rejected_with_conflict(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'inflight@example.com',
            'message_type' => 'password_reset',
            'template_id' => 'password_reset',
            'subject' => 'Reset',
            'payload' => ['user_name' => 'Charlie', 'reset_url' => 'https://example.com/r'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'last_attempted_at' => now(),
            'attempts' => 1,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/system/mail-deliveries/{$record->id}/retry");

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => "Cannot retry a message that is actively being processed by a worker (#{$record->id})."]);
    }

    public function test_admin_retry_on_sent_record_is_rejected_with_conflict(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'delivered@example.com',
            'message_type' => 'account_verification',
            'template_id' => 'account_verification',
            'subject' => 'Verify',
            'payload' => ['user_name' => 'Dave', 'verification_url' => 'https://example.com/v'],
            'status' => MailDeliveryRecord::STATUS_SENT,
            'sent_at' => now(),
            'attempts' => 1,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/system/mail-deliveries/{$record->id}/retry");

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => "Cannot retry a message that has already been delivered (#{$record->id})."]);
    }

    public function test_admin_cancel_on_in_flight_processing_record_is_rejected_with_conflict(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'cancel_inflight@example.com',
            'message_type' => 'billing_invoice',
            'template_id' => 'billing_invoice',
            'subject' => 'Invoice',
            'payload' => ['user_name' => 'Eve', 'invoice_number' => 'INV-2', 'amount' => '$10'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'last_attempted_at' => now(),
            'attempts' => 1,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/system/mail-deliveries/{$record->id}/cancel");

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => "Cannot cancel a message that is actively in-flight with a worker (#{$record->id})."]);
    }

    public function test_concurrent_retries_on_failed_record_prevents_duplicate_queue_dispatches(): void
    {
        Queue::fake();

        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'failed_retry@example.com',
            'message_type' => 'security_alert',
            'template_id' => 'security_alert',
            'subject' => 'Alert',
            'payload' => ['alert_title' => 'Incident', 'description' => 'Details'],
            'status' => MailDeliveryRecord::STATUS_FAILED,
            'failed_at' => now(),
            'attempts' => 3,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        // First retry transitions failed -> queued and dispatches job
        $result1 = $this->service->retry($record->id);
        $this->assertEquals(MailDeliveryRecord::STATUS_QUEUED, $result1->status);

        // Second retry on the now-queued record is idempotent and does not dispatch another job
        $result2 = $this->service->retry($record->id);
        $this->assertEquals(MailDeliveryRecord::STATUS_QUEUED, $result2->status);

        Queue::assertPushed(ProcessMailDeliveryJob::class, 1);
    }

    public function test_stale_recovery_preserves_active_worker_in_flight(): void
    {
        Queue::fake();

        // Active record: attempted 30 seconds ago (fresh, within 15m threshold)
        $activeRecord = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'active@example.com',
            'message_type' => 'account_verification',
            'template_id' => 'account_verification',
            'subject' => 'Verify',
            'payload' => ['user_name' => 'Frank', 'verification_url' => 'https://example.com/v'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 1,
            'max_attempts' => 3,
            'last_attempted_at' => Carbon::now()->subSeconds(30),
            'is_dry_run' => true,
        ]);

        $this->artisan('mail:recover-stale', ['--stale-seconds' => 900])
            ->assertExitCode(0);

        $refreshed = $activeRecord->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $refreshed->status);
        Queue::assertNothingPushed();
    }

    public function test_stale_recovery_reclaims_interrupted_worker_and_enforces_dead_letter(): void
    {
        Queue::fake();

        // Stale record: attempted 20 minutes ago (beyond 15m threshold), attempts = 2
        $staleRecord = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'crashed@example.com',
            'message_type' => 'billing_invoice',
            'template_id' => 'billing_invoice',
            'subject' => 'Invoice',
            'payload' => ['user_name' => 'Grace', 'invoice_number' => 'INV-3', 'amount' => '$5'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 2,
            'max_attempts' => 3,
            'last_attempted_at' => Carbon::now()->subMinutes(20),
            'is_dry_run' => true,
        ]);

        // Attempt 2 + 1 = 3 (reaches max_attempts) -> Dead-letter
        $result = $this->service->recoverStaleProcessing(staleSeconds: 900);
        $this->assertEquals(1, $result['stale_found']);
        $this->assertEquals(0, $result['recovered']);
        $this->assertEquals(1, $result['dead_lettered']);

        $refreshed = $staleRecord->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_FAILED, $refreshed->status);
        $this->assertStringContainsString('Worker timeout / crash beyond 900s threshold', $refreshed->last_error);
        $this->assertNotNull($refreshed->failed_at);
        Queue::assertNothingPushed();
    }

    public function test_concurrent_idempotency_key_submissions_serialize_cleanly(): void
    {
        Queue::fake();

        $key = 'concurrent_test_key_' . uniqid();

        $rec1 = $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'tenant_idemp@example.com',
            messageType: 'account_verification',
            subject: 'Verify',
            payload: ['user_name' => 'Heidi', 'verification_url' => 'https://example.com/v'],
            idempotencyKey: $key
        );

        $rec2 = $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'tenant_idemp@example.com',
            messageType: 'account_verification',
            subject: 'Verify Dup',
            payload: ['user_name' => 'Heidi', 'verification_url' => 'https://example.com/v'],
            idempotencyKey: $key
        );

        $this->assertEquals($rec1->id, $rec2->id);
        $this->assertEquals(1, MailDeliveryRecord::where('idempotency_key', $key)->count());
        Queue::assertPushed(ProcessMailDeliveryJob::class, 1);
    }

    public function test_tenant_rate_limiter_throttles_burst_enqueue(): void
    {
        Queue::fake();

        Config::set('mail_delivery.tenant_rate_limit_per_minute', 3);

        for ($i = 1; $i <= 3; $i++) {
            $this->service->enqueue(
                tenantId: $this->tenant->id,
                recipientEmail: "burst_{$i}@example.com",
                messageType: 'account_verification',
                subject: 'Verify',
                payload: ['user_name' => "User {$i}", 'verification_url' => 'https://example.com/v']
            );
        }

        // 4th request must be throttled
        $this->expectException(RateLimitExceededException::class);
        $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'burst_4@example.com',
            messageType: 'account_verification',
            subject: 'Verify',
            payload: ['user_name' => 'User 4', 'verification_url' => 'https://example.com/v']
        );
    }

    public function test_malformed_and_javascript_urls_in_payload_are_rejected(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage("Payload field 'verification_url' must be a valid HTTP or HTTPS URL.");

        $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'xss@example.com',
            messageType: 'account_verification',
            subject: 'Verify',
            payload: [
                'user_name' => 'Attacker',
                'verification_url' => 'javascript:alert(document.cookie)',
            ]
        );
    }

    public function test_blade_templates_strictly_escape_xss_content(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'xss_render@example.com',
            'message_type' => 'account_verification',
            'template_id' => 'account_verification',
            'subject' => 'Verify Email',
            'payload' => [
                'name' => '<script>alert("xss")</script>',
                'verification_url' => 'https://example.com/verify?token=123',
            ],
            'status' => MailDeliveryRecord::STATUS_QUEUED,
            'attempts' => 0,
            'max_attempts' => 3,
            'is_dry_run' => true,
        ]);

        $mailable = new TransactionalMail($record);
        $rendered = $mailable->render();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $rendered);
    }

    public function test_error_sanitizer_redacts_passwords_tokens_and_connection_secrets(): void
    {
        $job = new ProcessMailDeliveryJob(1);

        $dirtyMessage = 'Failed connecting to redis://default:SuperSecretPassword123@redis.internal:6379; bearer eyJhbGciOiJIUzI1NiJ9.testToken; token=raw_auth_secret_xyz';
        $cleaned = $job->sanitizeErrorMessage($dirtyMessage);

        $this->assertStringNotContainsString('SuperSecretPassword123', $cleaned);
        $this->assertStringNotContainsString('raw_auth_secret_xyz', $cleaned);
        $this->assertStringContainsString('[REDACTED]', $cleaned);
    }

    public function test_queue_configuration_validator_detects_misconfigurations(): void
    {
        // Default config should be valid
        $validHealth = $this->service->validateConfiguration();
        $this->assertTrue($validHealth['is_valid']);
        $this->assertEmpty($validHealth['issues']);

        // Misconfigure base_backoff > max_backoff
        Config::set('mail_delivery.base_backoff_seconds', 1000);
        Config::set('mail_delivery.max_backoff_seconds', 500);

        $invalidHealth = $this->service->validateConfiguration();
        $this->assertFalse($invalidHealth['is_valid']);
        $this->assertNotEmpty($invalidHealth['issues']);
        $this->assertStringContainsString('max_backoff_seconds (500) must be greater than or equal to base_backoff_seconds (1000)', $invalidHealth['issues'][0]);
    }

    public function test_operational_status_reports_accurate_metrics_and_config_health(): void
    {
        $status = $this->service->getOperationalStatus();

        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('configuration_health', $status);
        $this->assertTrue($status['configuration_health']['is_valid']);
        $this->assertArrayHasKey('metrics', $status);
        $this->assertArrayHasKey('dry_run_mode', $status);
        $this->assertTrue($status['dry_run_mode']);
    }
}
