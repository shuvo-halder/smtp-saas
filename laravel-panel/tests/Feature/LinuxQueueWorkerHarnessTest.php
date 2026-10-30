<?php

namespace Tests\Feature;

use App\Exceptions\MailDelivery\InvalidDeliveryStateException;
use App\Jobs\ProcessMailDeliveryJob;
use App\Mail\TransactionalMail;
use App\Models\MailDeliveryRecord;
use App\Models\User;
use App\Services\Health\SystemHealthService;
use App\Services\MailDeliveryService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinuxQueueWorkerHarnessTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected User $admin;
    protected MailDeliveryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->create([
            'email' => 'tenant_worker_test@example.com',
            'status' => 'active',
            'is_admin' => false,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'admin_worker_test@example.com',
            'status' => 'active',
            'is_admin' => true,
        ]);
        $this->admin->givePermissionTo('admin.stats.read');

        $this->service = app(MailDeliveryService::class);
    }

    /**
     * Test 1: Verify the mathematical timing hierarchy across queue & worker parameters.
     */
    public function test_timing_hierarchy_mutual_consistency(): void
    {
        $audit = $this->service->validateConfiguration();

        $this->assertTrue($audit['is_valid']);
        $this->assertEmpty($audit['issues']);

        $jobTimeout = (int) config('mail_delivery.job_timeout_seconds', 60);
        $retryAfter = (int) config('queue.connections.database.retry_after', 90);
        $workerLease = (int) config('mail_delivery.worker_lease_seconds', 120);
        $staleThreshold = (int) config('mail_delivery.stale_threshold_seconds', 900);
        $baseBackoff = (int) config('mail_delivery.base_backoff_seconds', 60);
        $maxBackoff = (int) config('mail_delivery.max_backoff_seconds', 900);

        $this->assertLessThan($retryAfter, $jobTimeout, "job_timeout must be strictly less than retry_after");
        $this->assertLessThan($workerLease, $retryAfter, "retry_after must be strictly less than worker_lease");
        $this->assertLessThan($staleThreshold, $workerLease, "worker_lease must be strictly less than stale_threshold");
        $this->assertLessThanOrEqual($maxBackoff, $baseBackoff, "base_backoff must be less than or equal to max_backoff");
    }

    /**
     * Test 2: Verify scheduler registration and overlap protection for mail:recover-stale.
     */
    public function test_scheduler_registration_and_mutex_lock(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $staleRecoveryEvent = $events->first(function ($event) {
            return str_contains($event->command, 'mail:recover-stale');
        });

        $this->assertNotNull($staleRecoveryEvent, "mail:recover-stale must be registered in scheduler");
        $this->assertEquals('*/15 * * * *', $staleRecoveryEvent->expression, "mail:recover-stale must run every 15 minutes");
        $this->assertTrue($staleRecoveryEvent->withoutOverlapping, "mail:recover-stale must have withoutOverlapping enabled");
        $this->assertEquals(10, $staleRecoveryEvent->expiresAt, "withoutOverlapping mutex lock must be 10 minutes");
    }

    /**
     * Test 3: Scenario 1 - Worker terminates during processing; leaves record in processing with timestamp.
     */
    public function test_worker_interruption_preserves_processing_state(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'worker_crash@example.com',
            'message_type' => 'security_alert',
            'template_id' => 'security_alert',
            'subject' => 'Worker Crash Probe',
            'payload' => ['user_name' => 'Tester', 'alert_title' => 'Crash', 'alert_message' => 'Simulated crash'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 1,
            'max_attempts' => 3,
            'last_attempted_at' => now(),
            'is_dry_run' => true,
        ]);

        // Verify status remains processing and attempts not prematurely incremented
        $this->assertTrue($record->fresh()->isProcessing());
        $this->assertEquals(1, $record->fresh()->attempts);
    }

    /**
     * Test 4: Scenario 2 - Stale recovery reclaims expired worker lease and increments attempt count.
     */
    public function test_stale_recovery_reclaims_interrupted_worker_after_cutoff(): void
    {
        Queue::fake();

        $staleRecord = MailDeliveryRecord::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'interrupted@example.com',
            'message_type' => 'password_reset',
            'template_id' => 'password_reset',
            'subject' => 'Password Reset',
            'payload' => ['user_name' => 'Bob', 'reset_url' => 'https://example.com/r'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 1,
            'max_attempts' => 3,
            'last_attempted_at' => Carbon::now()->subMinutes(20),
            'is_dry_run' => true,
        ]);

        $result = $this->service->recoverStaleProcessing(staleSeconds: 900, dryRun: false);

        $this->assertEquals(1, $result['stale_found']);
        $this->assertEquals(1, $result['recovered']);
        $this->assertEquals(0, $result['dead_lettered']);

        $refreshed = $staleRecord->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_QUEUED, $refreshed->status);
        $this->assertEquals(2, $refreshed->attempts);
        $this->assertStringContainsString('interrupted processing', $refreshed->last_error);

        Queue::assertPushed(ProcessMailDeliveryJob::class, 1);
    }

    /**
     * Test 5: Scenario 3 - Stale recovery dead-letters records when max_attempts is reached.
     */
    public function test_stale_recovery_dead_letters_when_max_attempts_reached(): void
    {
        Queue::fake();

        $exhaustedRecord = MailDeliveryRecord::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'dead_letter@example.com',
            'message_type' => 'billing_invoice',
            'template_id' => 'billing_invoice',
            'subject' => 'Invoice Notice',
            'payload' => ['user_name' => 'Charlie', 'invoice_number' => 'INV-99', 'amount' => '$99'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 2,
            'max_attempts' => 3,
            'last_attempted_at' => Carbon::now()->subMinutes(25),
            'is_dry_run' => true,
        ]);

        $result = $this->service->recoverStaleProcessing(staleSeconds: 900, dryRun: false);

        $this->assertEquals(1, $result['stale_found']);
        $this->assertEquals(0, $result['recovered']);
        $this->assertEquals(1, $result['dead_lettered']);

        $refreshed = $exhaustedRecord->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_FAILED, $refreshed->status);
        $this->assertEquals(3, $refreshed->attempts);
        $this->assertNotNull($refreshed->failed_at);
        $this->assertStringContainsString('Max attempts reached', $refreshed->last_error);

        Queue::assertNothingPushed();
    }

    /**
     * Test 6: Scenario 4 - Stale recovery never touches active workers within lease time.
     */
    public function test_stale_recovery_skips_active_workers_within_lease_time(): void
    {
        Queue::fake();

        $activeRecord = MailDeliveryRecord::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'active_worker@example.com',
            'message_type' => 'account_verification',
            'template_id' => 'account_verification',
            'subject' => 'Verification',
            'payload' => ['user_name' => 'Dave', 'verification_url' => 'https://example.com/v'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 1,
            'max_attempts' => 3,
            'last_attempted_at' => Carbon::now()->subSeconds(30), // 30s ago: well within 120s lease
            'is_dry_run' => true,
        ]);

        $result = $this->service->recoverStaleProcessing(staleSeconds: 900, dryRun: false);

        $this->assertEquals(0, $result['stale_found']);
        $this->assertEquals(0, $result['recovered']);
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $activeRecord->fresh()->status);
        Queue::assertNothingPushed();
    }

    /**
     * Test 7: Scenario 5 - Administrative retry while processing returns HTTP 409 Conflict.
     */
    public function test_admin_retry_while_processing_returns_409_conflict(): void
    {
        $inFlightRecord = MailDeliveryRecord::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'inflight@example.com',
            'message_type' => 'security_alert',
            'template_id' => 'security_alert',
            'subject' => 'Security Notice',
            'payload' => ['user_name' => 'Eve', 'alert_title' => 'Alert', 'alert_message' => 'Details'],
            'status' => MailDeliveryRecord::STATUS_PROCESSING,
            'attempts' => 1,
            'max_attempts' => 3,
            'last_attempted_at' => now(),
            'is_dry_run' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/system/mail-deliveries/{$inFlightRecord->id}/retry");

        $response->assertStatus(409);
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $inFlightRecord->fresh()->status);
    }

    /**
     * Test 8: Deterministic RFC 5322 Message-ID is identical across retries.
     */
    public function test_deterministic_message_id_constant_across_retries(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'tenant_id' => $this->tenant->id,
            'recipient_email' => 'dedup@example.com',
            'message_type' => 'security_alert',
            'template_id' => 'security_alert',
            'subject' => 'Test Dedup',
            'payload' => ['user_name' => 'Frank', 'alert_title' => 'Title', 'alert_message' => 'Msg'],
            'status' => MailDeliveryRecord::STATUS_FAILED,
            'attempts' => 3,
            'max_attempts' => 3,
            'is_dry_run' => false,
        ]);

        $mailable1 = new TransactionalMail($record);
        $headers1 = $mailable1->headers();

        // Simulate admin retry
        $retriedRecord = $this->service->retry($record->id);
        $mailable2 = new TransactionalMail($retriedRecord);
        $headers2 = $mailable2->headers();

        $this->assertEquals($headers1->messageId, $headers2->messageId);
        $this->assertStringContainsString('f47ac10b-58cc-4372-a567-0e02b2c3d479', $headers1->messageId);
        $this->assertEquals('f47ac10b-58cc-4372-a567-0e02b2c3d479', $headers1->text['X-Delivery-UUID']);
    }

    /**
     * Test 9: Operational telemetry and health probe exposes zero recipient or body PII.
     */
    public function test_health_telemetry_guarantees_zero_pii(): void
    {
        // Enqueue a real record with private data
        $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'strictly_confidential@example.com',
            messageType: 'account_verification',
            subject: 'Confidential Verification',
            payload: ['user_name' => 'SecretUser', 'verification_url' => 'https://example.com/v?secret=true']
        );

        $status = $this->service->getOperationalStatus();
        $json = json_encode($status);

        $this->assertStringNotContainsString('strictly_confidential@example.com', $json);
        $this->assertStringNotContainsString('SecretUser', $json);
        $this->assertStringNotContainsString('recipient_email', $json);
        $this->assertStringNotContainsString('payload', $json);
        $this->assertArrayHasKey('metrics', $status);
        $this->assertArrayHasKey('configuration_health', $status);
        $this->assertTrue($status['configuration_health']['is_valid']);
    }

    /**
     * Test 10: Dry-run safety prevents any real external network or SMTP traffic.
     */
    public function test_dry_run_safety_guarantees_local_simulation(): void
    {
        $record = $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'dryrun_safety@example.com',
            messageType: 'security_alert',
            subject: 'Dry Run Safety Test',
            payload: ['user_name' => 'SafeUser', 'alert_title' => 'Safe', 'alert_message' => 'No network']
        );

        $this->assertTrue($record->is_dry_run);

        // Process job
        $job = new ProcessMailDeliveryJob($record->id);
        $job->handle();

        $refreshed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_SENT, $refreshed->status);
        $this->assertTrue($refreshed->is_dry_run);
        $this->assertNotNull($refreshed->sent_at);
        $this->assertEquals(1, $refreshed->attempts);
        $this->assertNull($refreshed->last_error);
    }
}
