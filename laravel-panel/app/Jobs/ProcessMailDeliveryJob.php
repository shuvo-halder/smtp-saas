<?php

namespace App\Jobs;

use App\Exceptions\MailDelivery\PermanentDeliveryException;
use App\Exceptions\MailDelivery\TransientDeliveryException;
use App\Mail\TransactionalMail;
use App\Models\MailDeliveryRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class ProcessMailDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Handled dynamically per MailDeliveryRecord's max_attempts.
     */
    public int $tries = 5;

    /**
     * The maximum number of seconds the job can run before timing out.
     * Aligned with worker execution configuration and strictly less than queue retry_after.
     */
    public int $timeout = 60;

    /**
     * Unique ownership claim token established when claiming the delivery record.
     */
    public ?string $claimToken = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $recordId,
        ?string $claimToken = null
    ) {
        $this->claimToken = $claimToken;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $record = MailDeliveryRecord::find($this->recordId);

        if (!$record) {
            Log::warning("ProcessMailDeliveryJob: Record #{$this->recordId} not found. Skipping.");
            return;
        }

        // Idempotency: If already sent or cancelled, exit immediately
        if ($record->isSent() || $record->isCancelled()) {
            return;
        }

        $service = app(\App\Services\MailDeliveryService::class);
        $leaseSeconds = (int) config('mail_delivery.worker_lease_seconds', 120);

        // Generate a cryptographically secure, unique claim token for this execution attempt if not preset
        if (empty($this->claimToken)) {
            $this->claimToken = (string) Str::uuid();
        }

        // Atomic transition to processing with claim token fencing
        $claimed = $service->claimForProcessing($this->recordId, $this->claimToken, $leaseSeconds);

        if (!$claimed) {
            Log::info("ProcessMailDeliveryJob: Record #{$this->recordId} is active with another worker or in terminal state. Skipping.");
            return;
        }

        $record->refresh();

        $dryRun = (bool) config('mail_delivery.dry_run', true);
        $mailer = config('mail.default', 'log');

        try {
            if ($dryRun || $record->is_dry_run || $mailer === 'log' || $mailer === 'array') {
                // Dry-Run / Simulated Delivery
                Log::info("ProcessMailDeliveryJob: [DRY-RUN] Simulated delivery for message {$record->uuid} to {$record->recipient_email}", [
                    'message_type' => $record->message_type,
                    'template_id'  => $record->template_id,
                    'subject'      => $record->subject,
                ]);

                $completed = $service->completeDelivery($record->id, $this->claimToken, isDryRun: true, attempts: max(1, $record->attempts + 1));
                if (!$completed) {
                    Log::warning("ProcessMailDeliveryJob: Worker lost ownership for record #{$record->id} before dry-run completion. Fenced off.");
                }

                return;
            }

            // Live Mail Delivery Transport
            Mail::to($record->recipient_email)->send(new TransactionalMail($record));

            $completed = $service->completeDelivery($record->id, $this->claimToken, isDryRun: false, attempts: max(1, $record->attempts + 1));
            if (!$completed) {
                Log::warning("ProcessMailDeliveryJob: Worker lost ownership for record #{$record->id} before live send completion. Fenced off.");
            }
        } catch (Throwable $e) {
            $this->handleFailure($record, $e);
        }
    }

    /**
     * Handle job delivery failure with retryable vs permanent classification.
     */
    public function handleFailure(MailDeliveryRecord $record, Throwable $e): void
    {
        $sanitizedError = $this->sanitizeErrorMessage($e->getMessage());
        $isPermanent = ($e instanceof PermanentDeliveryException);
        $service = app(\App\Services\MailDeliveryService::class);

        // 1. Permanent Failure: Do not retry
        if ($isPermanent) {
            Log::error("ProcessMailDeliveryJob: Permanent failure for record #{$record->id}: {$sanitizedError}", [
                'record_uuid' => $record->uuid,
                'error_class' => get_class($e),
            ]);

            $result = $service->failDelivery(
                recordId: $record->id,
                claimToken: (string) $this->claimToken,
                errorMessage: $sanitizedError,
                isPermanent: true
            );

            if (!$result['affected']) {
                Log::warning("ProcessMailDeliveryJob: Worker lost ownership for record #{$record->id} during permanent failure. Fenced off.");
                return;
            }

            $this->fail($e);
            return;
        }

        // 2. Retryable / Transient Failure
        $result = $service->failDelivery(
            recordId: $record->id,
            claimToken: (string) $this->claimToken,
            errorMessage: $sanitizedError,
            isPermanent: false
        );

        if (!$result['affected']) {
            Log::warning("ProcessMailDeliveryJob: Worker lost ownership for record #{$record->id} during transient failure. Fenced off.");
            return;
        }

        if ($result['is_dead_letter']) {
            Log::error("ProcessMailDeliveryJob: Dead-lettered record #{$record->id} after exceeding max attempts: {$sanitizedError}");
            $this->fail($e);
            return;
        }

        Log::warning("ProcessMailDeliveryJob: Retrying record #{$record->id} in {$result['delay']}s: {$sanitizedError}");
        $this->release($result['delay']);
    }

    /**
     * Handle a job failure from queue worker.
     */
    public function failed(?Throwable $e): void
    {
        $record = MailDeliveryRecord::find($this->recordId);
        if (!$record || $record->isSent() || $record->isCancelled()) {
            return;
        }

        // If record is currently processing with an active claim token,
        // verify that this job instance holds the active claim.
        // Fenced-off workers must never overwrite an active owner or terminal state.
        if ($record->isProcessing() && $record->claim_token !== null) {
            if (empty($this->claimToken) || !hash_equals($record->claim_token, $this->claimToken)) {
                Log::warning("ProcessMailDeliveryJob: failed() callback fenced off for record #{$record->id}; owner mismatch.");
                return;
            }
        }

        $errorMessage = $e ? $this->sanitizeErrorMessage($e->getMessage()) : 'Job failed';

        MailDeliveryRecord::where('id', $record->id)
            ->whereNotIn('status', [MailDeliveryRecord::STATUS_SENT, MailDeliveryRecord::STATUS_CANCELLED])
            ->when($record->isProcessing() && !empty($this->claimToken), function ($q) {
                $q->where('claim_token', $this->claimToken);
            })
            ->update([
                'status'      => MailDeliveryRecord::STATUS_FAILED,
                'claim_token' => null,
                'failed_at'   => now(),
                'last_error'  => $errorMessage,
            ]);
    }

    /**
     * Sanitize error message to prevent leaking database passwords or internal traces.
     */
    public function sanitizeErrorMessage(string $message): string
    {
        // Strip sensitive strings, credentials, and authentication secrets
        $patterns = [
            '/:\/\/([^:\s]+):([^@\s]+)@/'             => '://$1:[REDACTED]@',
            '/password=([^;\s]+)/i'                   => 'password=[REDACTED]',
            '/(token|secret|key|api_key)=([^;&\s]+)/i' => '$1=[REDACTED]',
            '/bearer\s+[a-zA-Z0-9_\-\.]+/i'           => 'Bearer [REDACTED]',
            '/authorization:\s*([^\r\n]+)/i'          => 'Authorization: [REDACTED]',
            '/[a-zA-Z0-9]{32,}/'                      => '[REDACTED_HASH]',
        ];

        $cleaned = preg_replace(array_keys($patterns), array_values($patterns), $message);

        return mb_substr($cleaned ?? $message, 0, 500);
    }
}
