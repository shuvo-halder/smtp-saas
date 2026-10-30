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
     * Create a new job instance.
     */
    public function __construct(
        public int $recordId
    ) {}

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

        // Atomic transition to processing
        $affected = MailDeliveryRecord::where('id', $this->recordId)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status'            => 'processing',
                'last_attempted_at' => now(),
            ]);

        if ($affected === 0) {
            // Already updated or state changed concurrently
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

                $record->update([
                    'status'     => 'sent',
                    'attempts'   => max(1, $record->attempts + 1),
                    'sent_at'    => now(),
                    'is_dry_run' => true,
                    'last_error' => null,
                ]);

                return;
            }

            // Live Mail Delivery Transport
            Mail::to($record->recipient_email)->send(new TransactionalMail($record));

            $record->update([
                'status'     => 'sent',
                'attempts'   => max(1, $record->attempts + 1),
                'sent_at'    => now(),
                'is_dry_run' => false,
                'last_error' => null,
            ]);
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

        // 1. Permanent Failure: Do not retry
        if ($e instanceof PermanentDeliveryException) {
            Log::error("ProcessMailDeliveryJob: Permanent failure for record #{$record->id}: {$sanitizedError}", [
                'record_uuid' => $record->uuid,
                'error_class' => get_class($e),
            ]);

            $record->update([
                'status'     => 'failed',
                'failed_at'  => now(),
                'last_error' => $sanitizedError,
            ]);

            $this->fail($e);
            return;
        }

        // 2. Retryable / Transient Failure
        $newAttempts = $record->attempts + 1;
        $maxAttempts = $record->max_attempts ?: (int) config('mail_delivery.default_max_attempts', 3);

        if ($newAttempts >= $maxAttempts) {
            // Max attempts reached: Dead letter
            Log::error("ProcessMailDeliveryJob: Dead-lettered record #{$record->id} after {$newAttempts} attempts: {$sanitizedError}", [
                'record_uuid'  => $record->uuid,
                'attempts'     => $newAttempts,
                'max_attempts' => $maxAttempts,
            ]);

            $record->update([
                'attempts'   => $newAttempts,
                'status'     => 'failed',
                'failed_at'  => now(),
                'last_error' => "Max attempts ({$maxAttempts}) exceeded: " . $sanitizedError,
            ]);

            $this->fail($e);
            return;
        }

        // Release back to queue with exponential backoff & jitter
        $baseBackoff = (int) config('mail_delivery.base_backoff_seconds', 60);
        $maxBackoff = (int) config('mail_delivery.max_backoff_seconds', 900);
        $delay = app(MailDeliveryService::class)->calculateBackoffSeconds($newAttempts, $baseBackoff, $maxBackoff);

        Log::warning("ProcessMailDeliveryJob: Retrying record #{$record->id} (attempt {$newAttempts}/{$maxAttempts}) in {$delay}s: {$sanitizedError}");

        $record->update([
            'attempts'   => $newAttempts,
            'status'     => 'queued',
            'last_error' => $sanitizedError,
        ]);

        $this->release($delay);
    }

    /**
     * Handle a job failure from queue worker.
     */
    public function failed(?Throwable $e): void
    {
        $record = MailDeliveryRecord::find($this->recordId);
        if (!$record) {
            return;
        }

        $errorMessage = $e ? $this->sanitizeErrorMessage($e->getMessage()) : 'Job failed';

        $record->update([
            'status'     => MailDeliveryRecord::STATUS_FAILED,
            'failed_at'  => now(),
            'last_error' => $errorMessage,
        ]);
    }

    /**
     * Sanitize error message to prevent leaking database passwords or internal traces.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        // Strip sensitive strings or credentials
        $patterns = [
            '/password=([^;\s]+)/i' => 'password=[REDACTED]',
            '/bearer\s+[a-zA-Z0-9_\-\.]+/i' => 'Bearer [REDACTED]',
            '/[a-zA-Z0-9]{32,}/' => '[REDACTED_HASH]',
        ];

        $cleaned = preg_replace(array_keys($patterns), array_values($patterns), $message);

        return mb_substr($cleaned ?? $message, 0, 500);
    }
}
