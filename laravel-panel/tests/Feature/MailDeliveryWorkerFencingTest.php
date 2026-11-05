<?php

namespace Tests\Feature;

use App\Exceptions\MailDelivery\InvalidDeliveryStateException;
use App\Exceptions\MailDelivery\PermanentDeliveryException;
use App\Jobs\ProcessMailDeliveryJob;
use App\Models\MailDeliveryRecord;
use App\Models\User;
use App\Services\MailDeliveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailDeliveryWorkerFencingTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected User $admin;
    protected MailDeliveryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->create([
            'email' => 'fencing_tenant@example.com',
            'status' => 'active',
            'is_admin' => false,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'fencing_admin@example.com',
            'status' => 'active',
            'is_admin' => true,
        ]);
        $this->admin->givePermissionTo('admin.stats.read');

        $this->service = app(MailDeliveryService::class);
        Queue::fake();
    }

    /**
     * Scenario A — Old worker resumes after reclaim.
     * Worker A claims token A -> becomes stale -> recovery revokes token A ->
     * Worker B claims token B -> Worker A attempts mark sent -> 0 rows affected -> Worker B retains ownership.
     */
    public function test_scenario_a_old_worker_resumes_after_reclaim_is_fenced_off(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid'              => (string) Str::uuid(),
            'tenant_id'         => $this->tenant->id,
            'recipient_email'   => 'worker_fencing_a@example.com',
            'message_type'      => 'security_alert',
            'template_id'       => 'security_alert',
            'subject'           => 'Fencing Test A',
            'payload'           => ['user_name' => 'Alice'],
            'status'            => MailDeliveryRecord::STATUS_QUEUED,
            'attempts'          => 0,
            'max_attempts'      => 3,
            'claim_token'       => null,
            'is_dry_run'        => true,
        ]);

        $tokenA = 'worker_token_aaa_111';
        $tokenB = 'worker_token_bbb_222';

        // 1. Worker A claims the record
        $claimedA = $this->service->claimForProcessing($record->id, $tokenA, 120);
        $this->assertTrue($claimedA, "Worker A must successfully acquire ownership");

        $refreshedA = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $refreshedA->status);
        $this->assertEquals($tokenA, $refreshedA->claim_token);

        // 2. Worker A becomes stale (e.g. frozen for 20 minutes)
        $refreshedA->update(['last_attempted_at' => Carbon::now()->subMinutes(20)]);

        // 3. Stale recovery runs and reclaims the delivery
        $recoveryResult = $this->service->recoverStaleProcessing(staleSeconds: 900, dryRun: false);
        $this->assertEquals(1, $recoveryResult['stale_found']);
        $this->assertEquals(1, $recoveryResult['recovered']);

        $reclaimed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_QUEUED, $reclaimed->status);
        $this->assertNull($reclaimed->claim_token, "Recovery must invalidate Worker A's claim token");

        // 4. Worker B claims the delivery with Token B
        $claimedB = $this->service->claimForProcessing($record->id, $tokenB, 120);
        $this->assertTrue($claimedB, "Worker B must successfully acquire ownership");

        $refreshedB = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $refreshedB->status);
        $this->assertEquals($tokenB, $refreshedB->claim_token);

        // 5. Worker A awakens and attempts to complete delivery with Token A
        $completedA = $this->service->completeDelivery($record->id, $tokenA, isDryRun: true);
        $this->assertFalse($completedA, "Worker A must be fenced off and affect zero rows");

        // Verify Worker B still retains ownership and record is NOT marked sent
        $afterZombieA = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $afterZombieA->status);
        $this->assertEquals($tokenB, $afterZombieA->claim_token);
        $this->assertNull($afterZombieA->sent_at);

        // 6. Worker B successfully completes delivery with Token B
        $completedB = $this->service->completeDelivery($record->id, $tokenB, isDryRun: true);
        $this->assertTrue($completedB, "Worker B must successfully complete delivery");

        $final = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_SENT, $final->status);
        $this->assertNull($final->claim_token, "Claim token must be cleared upon terminal completion");
        $this->assertNotNull($final->sent_at);
    }

    /**
     * Scenario B — Old worker fails after reclaim.
     * Worker A attempts to record a permanent or dead-letter failure after Worker B acquired claim.
     * The old failure update must be rejected and must not overwrite Worker B's status or last_error.
     */
    public function test_scenario_b_old_worker_fails_after_reclaim_is_fenced_off(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid'              => (string) Str::uuid(),
            'tenant_id'         => $this->tenant->id,
            'recipient_email'   => 'worker_fencing_b@example.com',
            'message_type'      => 'password_reset',
            'template_id'       => 'password_reset',
            'subject'           => 'Fencing Test B',
            'payload'           => ['user_name' => 'Bob'],
            'status'            => MailDeliveryRecord::STATUS_QUEUED,
            'attempts'          => 0,
            'max_attempts'      => 3,
            'is_dry_run'        => true,
        ]);

        $tokenA = 'token_fail_old_A';
        $tokenB = 'token_active_new_B';

        // Worker A claims -> becomes stale -> recovery reclaims -> Worker B claims
        $this->service->claimForProcessing($record->id, $tokenA);
        $record->update(['last_attempted_at' => Carbon::now()->subMinutes(20)]);
        $this->service->recoverStaleProcessing(staleSeconds: 900);
        $this->service->claimForProcessing($record->id, $tokenB);

        // Worker A attempts permanent failure with token A
        $result = $this->service->failDelivery(
            recordId: $record->id,
            claimToken: $tokenA,
            errorMessage: 'Stale worker A error: Connection timed out',
            isPermanent: true
        );

        $this->assertFalse($result['affected'], "Stale worker failure update must affect zero rows");

        $refreshed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $refreshed->status);
        $this->assertEquals($tokenB, $refreshed->claim_token);
        $this->assertStringNotContainsString('Stale worker A error', (string) $refreshed->last_error);
    }

    /**
     * Scenario C — Old worker releases after reclaim.
     * Verify an old worker cannot return a newly owned delivery to the queue.
     */
    public function test_scenario_c_old_worker_releases_after_reclaim_is_fenced_off(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid'              => (string) Str::uuid(),
            'tenant_id'         => $this->tenant->id,
            'recipient_email'   => 'worker_fencing_c@example.com',
            'message_type'      => 'billing_invoice',
            'template_id'       => 'billing_invoice',
            'subject'           => 'Fencing Test C',
            'payload'           => ['amount' => '100'],
            'status'            => MailDeliveryRecord::STATUS_QUEUED,
            'attempts'          => 0,
            'max_attempts'      => 3,
            'is_dry_run'        => true,
        ]);

        $tokenA = 'token_transient_old_A';
        $tokenB = 'token_active_new_B';

        $this->service->claimForProcessing($record->id, $tokenA);
        $record->update(['last_attempted_at' => Carbon::now()->subMinutes(20)]);
        $this->service->recoverStaleProcessing(staleSeconds: 900);
        $this->service->claimForProcessing($record->id, $tokenB);

        // Worker A attempts transient retry release with token A
        $result = $this->service->failDelivery(
            recordId: $record->id,
            claimToken: $tokenA,
            errorMessage: 'Transient SMTP deferral from old worker A',
            isPermanent: false
        );

        $this->assertFalse($result['affected'], "Old worker transient release must affect zero rows");

        $refreshed = $record->fresh();
        // Record must remain in 'processing' under Worker B, not returned to 'queued'
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $refreshed->status);
        $this->assertEquals($tokenB, $refreshed->claim_token);
    }

    /**
     * Scenario D — Recovery races with worker completion.
     * Verify atomic conditional updates ensure that only a valid owner can complete the delivery.
     */
    public function test_scenario_d_recovery_races_with_worker_completion(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid'              => (string) Str::uuid(),
            'tenant_id'         => $this->tenant->id,
            'recipient_email'   => 'worker_fencing_d@example.com',
            'message_type'      => 'account_verification',
            'template_id'       => 'account_verification',
            'subject'           => 'Fencing Test D',
            'payload'           => ['user_name' => 'Dan'],
            'status'            => MailDeliveryRecord::STATUS_QUEUED,
            'attempts'          => 0,
            'max_attempts'      => 3,
            'is_dry_run'        => true,
        ]);

        $tokenA = 'token_race_A';
        $this->service->claimForProcessing($record->id, $tokenA);

        // Case 1: Worker completes just before recovery runs
        $completed = $this->service->completeDelivery($record->id, $tokenA, isDryRun: true);
        $this->assertTrue($completed);

        // Recovery runs with 0s threshold
        $recoveryResult = $this->service->recoverStaleProcessing(staleSeconds: 0);
        $this->assertEquals(0, $recoveryResult['stale_found'], "Recovery must ignore already sent record");
        $this->assertEquals(MailDeliveryRecord::STATUS_SENT, $record->fresh()->status);
    }

    /**
     * Scenario E — Duplicate worker execution.
     * Verify two workers cannot both successfully acquire ownership of the same delivery at the same time.
     */
    public function test_scenario_e_duplicate_worker_execution_only_one_claims(): void
    {
        $record = MailDeliveryRecord::create([
            'uuid'              => (string) Str::uuid(),
            'tenant_id'         => $this->tenant->id,
            'recipient_email'   => 'worker_fencing_e@example.com',
            'message_type'      => 'security_alert',
            'template_id'       => 'security_alert',
            'subject'           => 'Fencing Test E',
            'payload'           => ['user_name' => 'Eve'],
            'status'            => MailDeliveryRecord::STATUS_QUEUED,
            'attempts'          => 0,
            'max_attempts'      => 3,
            'is_dry_run'        => true,
        ]);

        $token1 = 'worker_first_claim';
        $token2 = 'worker_second_claim';

        $claimed1 = $this->service->claimForProcessing($record->id, $token1, leaseSeconds: 120);
        $claimed2 = $this->service->claimForProcessing($record->id, $token2, leaseSeconds: 120);

        $this->assertTrue($claimed1, "First worker must acquire ownership");
        $this->assertFalse($claimed2, "Second worker must be rejected because record is actively claimed");

        $refreshed = $record->fresh();
        $this->assertEquals($token1, $refreshed->claim_token);
        $this->assertEquals(MailDeliveryRecord::STATUS_PROCESSING, $refreshed->status);
    }

    /**
     * Scenario F — Terminal state protection.
     * Verify stale workers cannot overwrite sent, failed, or cancelled records.
     */
    public function test_scenario_f_terminal_state_protection(): void
    {
        $statuses = [
            MailDeliveryRecord::STATUS_SENT,
            MailDeliveryRecord::STATUS_FAILED,
            MailDeliveryRecord::STATUS_CANCELLED,
        ];

        foreach ($statuses as $terminalStatus) {
            $record = MailDeliveryRecord::create([
                'uuid'              => (string) Str::uuid(),
                'tenant_id'         => $this->tenant->id,
                'recipient_email'   => "terminal_{$terminalStatus}@example.com",
                'message_type'      => 'security_alert',
                'template_id'       => 'security_alert',
                'subject'           => "Terminal {$terminalStatus}",
                'payload'           => ['title' => 'Notice'],
                'status'            => $terminalStatus,
                'attempts'          => 1,
                'max_attempts'      => 3,
                'claim_token'       => null,
                'is_dry_run'        => true,
            ]);

            $staleToken = 'zombie_token_' . $terminalStatus;

            // 1. Cannot claim terminal record
            $claimed = $this->service->claimForProcessing($record->id, $staleToken);
            $this->assertFalse($claimed, "Cannot claim a record in terminal state: {$terminalStatus}");

            // 2. Cannot complete terminal record
            $completed = $this->service->completeDelivery($record->id, $staleToken);
            $this->assertFalse($completed, "Cannot complete a record in terminal state: {$terminalStatus}");

            // 3. Cannot fail terminal record
            $failed = $this->service->failDelivery($record->id, $staleToken, 'Zombie failure', isPermanent: true);
            $this->assertFalse($failed['affected'], "Cannot fail a record in terminal state: {$terminalStatus}");

            $this->assertEquals($terminalStatus, $record->fresh()->status);
        }
    }

    /**
     * Scenario G — Retry and cancellation.
     * Verify existing admin retry/cancel APIs preserve their HTTP 409 behavior and cannot invalidate an active worker's legitimate state unexpectedly.
     */
    public function test_scenario_g_retry_and_cancellation(): void
    {
        $token = 'active_worker_token';
        $activeRecord = MailDeliveryRecord::create([
            'uuid'              => (string) Str::uuid(),
            'tenant_id'         => $this->tenant->id,
            'recipient_email'   => 'admin_conflict@example.com',
            'message_type'      => 'security_alert',
            'template_id'       => 'security_alert',
            'subject'           => 'Conflict Notice',
            'payload'           => ['title' => 'Conflict'],
            'status'            => MailDeliveryRecord::STATUS_PROCESSING,
            'claim_token'       => $token,
            'attempts'          => 1,
            'max_attempts'      => 3,
            'last_attempted_at' => now(),
            'is_dry_run'        => true,
        ]);

        // 1. Retry on processing record returns HTTP 409 Conflict
        $responseRetry = $this->actingAs($this->admin)
            ->postJson("/api/admin/system/mail-deliveries/{$activeRecord->id}/retry");
        $responseRetry->assertStatus(409);
        $this->assertEquals($token, $activeRecord->fresh()->claim_token);

        // 2. Cancel on processing record returns HTTP 409 Conflict
        $responseCancel = $this->actingAs($this->admin)
            ->postJson("/api/admin/system/mail-deliveries/{$activeRecord->id}/cancel");
        $responseCancel->assertStatus(409);
        $this->assertEquals($token, $activeRecord->fresh()->claim_token);

        // 3. Retry on failed record clears any residual claim_token
        $failedRecord = MailDeliveryRecord::create([
            'uuid'              => (string) Str::uuid(),
            'tenant_id'         => $this->tenant->id,
            'recipient_email'   => 'failed_retry@example.com',
            'message_type'      => 'security_alert',
            'template_id'       => 'security_alert',
            'subject'           => 'Failed Retry',
            'payload'           => ['title' => 'Retry'],
            'status'            => MailDeliveryRecord::STATUS_FAILED,
            'claim_token'       => 'residual_token',
            'attempts'          => 3,
            'max_attempts'      => 3,
            'is_dry_run'        => true,
        ]);

        $retrySuccess = $this->actingAs($this->admin)
            ->postJson("/api/admin/system/mail-deliveries/{$failedRecord->id}/retry");
        $retrySuccess->assertStatus(200);
        $this->assertEquals(MailDeliveryRecord::STATUS_QUEUED, $failedRecord->fresh()->status);
        $this->assertNull($failedRecord->fresh()->claim_token, "Retry must clear residual claim token");
    }

    /**
     * Scenario H — Backward compatibility.
     * Verify existing records with a null claim token are handled safely and old queue records are not accidentally treated as valid active ownership.
     */
    public function test_scenario_h_backward_compatibility(): void
    {
        $legacyRecord = MailDeliveryRecord::create([
            'uuid'              => (string) Str::uuid(),
            'tenant_id'         => $this->tenant->id,
            'recipient_email'   => 'legacy_null_token@example.com',
            'message_type'      => 'security_alert',
            'template_id'       => 'security_alert',
            'subject'           => 'Legacy Record',
            'payload'           => ['title' => 'Legacy'],
            'status'            => MailDeliveryRecord::STATUS_QUEUED,
            'attempts'          => 0,
            'max_attempts'      => 3,
            'claim_token'       => null,
            'is_dry_run'        => true,
        ]);

        // Attempting to complete with empty/null token must fail
        $completedEmpty = $this->service->completeDelivery($legacyRecord->id, '');
        $this->assertFalse($completedEmpty, "Empty claim token must never be accepted for completion");

        // Attempting to fail with empty/null token must fail
        $failedEmpty = $this->service->failDelivery($legacyRecord->id, '', 'Error');
        $this->assertFalse($failedEmpty['affected'], "Empty claim token must never be accepted for failure");

        // Claiming from queued establishes a valid non-null token
        $newToken = (string) Str::uuid();
        $claimed = $this->service->claimForProcessing($legacyRecord->id, $newToken);
        $this->assertTrue($claimed);
        $this->assertEquals($newToken, $legacyRecord->fresh()->claim_token);
    }

    /**
     * Scenario I — End-to-end ProcessMailDeliveryJob worker execution with fencing protection.
     */
    public function test_scenario_i_end_to_end_job_worker_execution_with_fencing(): void
    {
        $record = $this->service->enqueue(
            tenantId: $this->tenant->id,
            recipientEmail: 'e2e_fencing@example.com',
            messageType: 'security_alert',
            subject: 'E2E Fencing Probe',
            payload: ['user_name' => 'Zoe', 'alert_title' => 'E2E', 'alert_message' => 'Test']
        );

        $this->assertEquals(MailDeliveryRecord::STATUS_QUEUED, $record->status);
        $this->assertNull($record->claim_token);

        // Worker instance executes handle()
        $job = new ProcessMailDeliveryJob($record->id);
        $job->handle();

        $refreshed = $record->fresh();
        $this->assertEquals(MailDeliveryRecord::STATUS_SENT, $refreshed->status);
        $this->assertNull($refreshed->claim_token, "Token cleared upon completion");
        $this->assertNotNull($refreshed->sent_at);

        // If an old job with a fabricated token attempts to overwrite
        $staleCompleted = $this->service->completeDelivery($record->id, 'fabricated_stale_token');
        $this->assertFalse($staleCompleted);
        $this->assertEquals(MailDeliveryRecord::STATUS_SENT, $record->fresh()->status);
    }
}
