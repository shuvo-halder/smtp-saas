<?php

namespace App\Services;

use App\Exceptions\MailDelivery\InvalidPayloadException;
use App\Exceptions\MailDelivery\InvalidRecipientException;
use App\Exceptions\MailDelivery\UnauthorizedRecipientException;
use App\Exceptions\MailDelivery\UnsupportedMessageTypeException;
use App\Jobs\ProcessMailDeliveryJob;
use App\Mail\TransactionalMail;
use App\Models\MailDeliveryRecord;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class MailDeliveryService
{
    /**
     * Enumerated application-level transactional message types.
     */
    public const MESSAGE_TYPES = [
        'account_verification',
        'password_reset',
        'billing_invoice',
        'security_alert',
    ];

    /**
     * Sensitive keys that must be masked or redacted before storage.
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'secret',
        'token',
        'verification_token',
        'reset_token',
        'key',
        'private_key',
        'api_key',
        'auth',
        'credentials',
        'bearer',
    ];

    /**
     * Enqueue a transactional mail delivery request.
     *
     * Supports both array parameter associative format: enqueue([...])
     * and fluent/named arguments: enqueue(tenantId: 1, recipientEmail: '...')
     *
     * @param array|int|null $tenantId
     * @param string|null $recipientEmail
     * @param string|null $messageType
     * @param string|null $subject
     * @param array $payload
     * @param string|null $idempotencyKey
     * @param int|null $maxAttempts
     * @param string|null $templateId
     * @return MailDeliveryRecord
     * @throws InvalidRecipientException
     * @throws UnsupportedMessageTypeException
     * @throws InvalidPayloadException
     * @throws UnauthorizedRecipientException
     */
    public function enqueue(
        array|int|null $tenantId = null,
        ?string $recipientEmail = null,
        ?string $messageType = null,
        ?string $subject = null,
        array $payload = [],
        ?string $idempotencyKey = null,
        ?int $maxAttempts = null,
        ?string $templateId = null
    ): MailDeliveryRecord {
        if (is_array($tenantId)) {
            $params = $tenantId;
            $recipientEmail = trim((string) ($params['recipient_email'] ?? ''));
            $messageType = trim((string) ($params['message_type'] ?? ''));
            $tenantId = isset($params['tenant_id']) ? (int) $params['tenant_id'] : null;
            $templateId = trim((string) ($params['template_id'] ?? $messageType));
            $subject = trim((string) ($params['subject'] ?? ''));
            $payload = (array) ($params['payload'] ?? []);
            $idempotencyKey = !empty($params['idempotency_key']) ? trim((string) $params['idempotency_key']) : null;
            $maxAttempts = isset($params['max_attempts']) ? max(1, min(5, (int) $params['max_attempts'])) : (int) config('mail_delivery.default_max_attempts', 3);
        } else {
            $recipientEmail = trim((string) ($recipientEmail ?? ''));
            $messageType = trim((string) ($messageType ?? ''));
            $tenantId = $tenantId !== null ? (int) $tenantId : null;
            $templateId = trim((string) ($templateId ?? $messageType));
            $subject = trim((string) ($subject ?? ''));
            $payload = (array) $payload;
            $idempotencyKey = !empty($idempotencyKey) ? trim((string) $idempotencyKey) : null;
            $maxAttempts = $maxAttempts !== null ? max(1, min(5, (int) $maxAttempts)) : (int) config('mail_delivery.default_max_attempts', 3);
        }

        // 1. Recipient Email Validation
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidRecipientException("Invalid recipient email address format: '{$recipientEmail}'");
        }

        // 2. Message Type Validation
        if (!in_array($messageType, self::MESSAGE_TYPES, true)) {
            throw new UnsupportedMessageTypeException("Unsupported transactional message type: '{$messageType}'");
        }

        // 3. Template Identifier Validation
        if (!array_key_exists($templateId, TransactionalMail::TEMPLATE_VIEWS)) {
            throw new InvalidPayloadException("Unsupported template identifier: '{$templateId}'");
        }

        // 4. Payload Validation per Message Type
        $this->validatePayload($messageType, $payload);

        // 5. Tenant Context & Authorization Validation
        if ($tenantId !== null) {
            $tenant = User::find($tenantId);
            if (!$tenant) {
                throw new UnauthorizedRecipientException("Tenant ID {$tenantId} does not exist.");
            }
        }

        // Default Subject if empty
        if (empty($subject)) {
            $subject = $this->getDefaultSubject($messageType, $payload);
        }
        $subject = strip_tags($subject);

        // 6. Sensitive Data Scrubbing
        $sanitizedPayload = $this->scrubPayload($payload);

        // 7. Atomic Idempotency / Deduplication Handling
        if ($idempotencyKey !== null) {
            $existing = MailDeliveryRecord::query()
                ->where('idempotency_key', $idempotencyKey)
                ->when($tenantId !== null, fn($q) => $q->where('tenant_id', $tenantId))
                ->latest('id')
                ->first();

            if ($existing !== null && in_array($existing->status, ['queued', 'processing', 'sent'], true)) {
                Log::info("MailDeliveryService: Idempotent duplicate detected for key {$idempotencyKey}. Returning existing record #{$existing->id}.");
                return $existing;
            }
        }

        $dryRun = (bool) config('mail_delivery.dry_run', true);

        // 8. Create Delivery Record
        $record = MailDeliveryRecord::create([
            'uuid'            => (string) Str::uuid(),
            'tenant_id'       => $tenantId,
            'recipient_email' => $recipientEmail,
            'message_type'    => $messageType,
            'template_id'     => $templateId,
            'subject'         => $subject,
            'payload'         => $sanitizedPayload,
            'status'          => 'queued',
            'attempts'        => 0,
            'max_attempts'    => $maxAttempts,
            'idempotency_key' => $idempotencyKey,
            'is_dry_run'      => $dryRun,
        ]);

        // 9. Dispatch Queue Job
        ProcessMailDeliveryJob::dispatch($record->id);

        return $record;
    }

    /**
     * Retry an eligible failed or cancelled mail delivery record.
     * Idempotent: returns existing record unchanged if already queued, processing, or sent.
     */
    public function retry(int $recordId, ?User $actor = null): MailDeliveryRecord
    {
        $query = MailDeliveryRecord::query();

        // Enforce tenant isolation if actor is a tenant
        if ($actor && $actor->role === 'tenant') {
            $query->forTenant($actor->id);
        }

        $record = $query->findOrFail($recordId);

        // Idempotency: If already delivered, cannot retry
        if ($record->isSent()) {
            return $record;
        }

        // Idempotency: If already in-flight or queued, return unchanged
        if ($record->isQueued() || $record->isProcessing()) {
            return $record;
        }

        // Transition back to queued
        $record->update([
            'status'            => 'queued',
            'attempts'          => 0,
            'failed_at'         => null,
            'cancelled_at'      => null,
            'last_error'        => null,
            'last_attempted_at' => null,
        ]);

        // Re-dispatch queue job
        ProcessMailDeliveryJob::dispatch($record->id);

        Log::info("MailDeliveryService: Record #{$record->id} manually retried by " . ($actor ? $actor->email : 'system'));

        return $record->fresh();
    }

    /**
     * Cancel a pending queued mail delivery record.
     * Idempotent: returns existing record if already cancelled or terminal.
     */
    public function cancel(int $recordId, ?User $actor = null): MailDeliveryRecord
    {
        $query = MailDeliveryRecord::query();

        if ($actor && $actor->role === 'tenant') {
            $query->forTenant($actor->id);
        }

        $record = $query->findOrFail($recordId);

        // Cannot cancel already sent messages
        if ($record->isSent()) {
            return $record;
        }

        // Already cancelled or failed
        if ($record->isCancelled() || $record->isFailed()) {
            return $record;
        }

        $record->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        Log::info("MailDeliveryService: Record #{$record->id} cancelled by " . ($actor ? $actor->email : 'system'));

        return $record->fresh();
    }

    /**
     * Recover records stuck in 'processing' status due to worker interruption or crash.
     *
     * @param int $staleSeconds
     * @param bool $dryRun
     * @return array{stale_found: int, recovered: int, dead_lettered: int, dry_run: bool}
     */
    public function recoverStaleProcessing(int $staleSeconds = 900, bool $dryRun = false): array
    {
        $staleRecords = MailDeliveryRecord::staleProcessing($staleSeconds)->get();
        $staleFound = $staleRecords->count();
        $recovered = 0;
        $deadLettered = 0;

        if ($dryRun) {
            return [
                'stale_found'   => $staleFound,
                'recovered'     => 0,
                'dead_lettered' => 0,
                'dry_run'       => true,
            ];
        }

        foreach ($staleRecords as $record) {
            $newAttempts = $record->attempts + 1;
            $maxAttempts = $record->max_attempts ?: (int) config('mail_delivery.default_max_attempts', 3);

            if ($newAttempts >= $maxAttempts) {
                $record->update([
                    'attempts'   => $newAttempts,
                    'status'     => 'failed',
                    'failed_at'  => now(),
                    'last_error' => "Worker timeout / interrupted beyond {$staleSeconds}s threshold. Max attempts reached.",
                ]);
                $deadLettered++;
            } else {
                $record->update([
                    'attempts'   => $newAttempts,
                    'status'     => 'queued',
                    'last_error' => "Recovered from interrupted processing state (attempt {$newAttempts}/{$maxAttempts}).",
                ]);
                ProcessMailDeliveryJob::dispatch($record->id);
                $recovered++;
            }
        }

        return [
            'stale_found'   => $staleFound,
            'recovered'     => $recovered,
            'dead_lettered' => $deadLettered,
            'dry_run'       => false,
        ];
    }

    /**
     * Retrieve aggregate operational metrics and health status for administrators.
     * Zero recipient PII or message body content is exposed.
     *
     * @return array<string, mixed>
     */
    public function getOperationalStatus(): array
    {
        try {
            $counts = MailDeliveryRecord::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->all();

            $total = (int) array_sum($counts);
            $queued = (int) ($counts['queued'] ?? 0);
            $processing = (int) ($counts['processing'] ?? 0);
            $sent = (int) ($counts['sent'] ?? 0);
            $failed = (int) ($counts['failed'] ?? 0);
            $cancelled = (int) ($counts['cancelled'] ?? 0);

            // Dead letter: failed records where attempts >= max_attempts
            $deadLetter = (int) MailDeliveryRecord::failed()
                ->whereColumn('attempts', '>=', 'max_attempts')
                ->count();

            // Total retries: count of attempts beyond attempt 1
            $totalRetried = (int) MailDeliveryRecord::where('attempts', '>', 1)->count();

            // Stale processing records
            $staleThreshold = (int) config('mail_delivery.stale_threshold_seconds', 900);
            $staleProcessing = (int) MailDeliveryRecord::staleProcessing($staleThreshold)->count();

            // Oldest pending job age
            $oldestQueued = MailDeliveryRecord::queued()->oldest('created_at')->first();
            $oldestPendingAgeSeconds = $oldestQueued ? (int) now()->diffInSeconds($oldestQueued->created_at) : null;

            // Last delivery timestamps
            $lastSent = MailDeliveryRecord::sent()->latest('sent_at')->first();
            $lastAttempt = MailDeliveryRecord::whereNotNull('last_attempted_at')->latest('last_attempted_at')->first();
        } catch (Throwable) {
            $total = 0;
            $queued = 0;
            $processing = 0;
            $sent = 0;
            $failed = 0;
            $cancelled = 0;
            $deadLetter = 0;
            $totalRetried = 0;
            $staleProcessing = 0;
            $oldestPendingAgeSeconds = null;
            $lastSent = null;
            $lastAttempt = null;
        }

        // Database Queue Backend Telemetry
        $queueDriver = config('queue.default', 'database');
        $dbPendingJobs = 0;
        $dbFailedJobs24h = 0;

        try {
            if ($queueDriver === 'database') {
                $dbPendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
                $dbFailedJobs24h = Schema::hasTable('failed_jobs')
                    ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours(24))->count()
                    : 0;
            }
        } catch (Throwable) {
            // Non-blocking
        }

        $dryRun = (bool) config('mail_delivery.dry_run', true);

        $status = 'HEALTHY';
        if ($staleProcessing > 0 || $deadLetter > 5 || $dbFailedJobs24h > 10) {
            $status = 'DEGRADED';
        }

        return [
            'status'       => $status,
            'timestamp'    => now()->toIso8601String(),
            'dry_run_mode' => $dryRun,
            'queue_driver' => $queueDriver,
            'metrics'      => [
                'total_deliveries'           => $total,
                'queued_count'               => $queued,
                'processing_count'           => $processing,
                'sent_count'                 => $sent,
                'failed_count'               => $failed,
                'cancelled_count'            => $cancelled,
                'dead_letter_count'          => $deadLetter,
                'retried_count'              => $totalRetried,
                'stale_processing_count'     => $staleProcessing,
                'oldest_pending_age_seconds' => $oldestPendingAgeSeconds,
                'last_attempted_at'          => $lastAttempt?->last_attempted_at?->toIso8601String(),
                'last_sent_at'               => $lastSent?->sent_at?->toIso8601String(),
            ],
            'database_queue' => [
                'pending_jobs_table_count'    => $dbPendingJobs,
                'failed_jobs_table_count_24h' => $dbFailedJobs24h,
            ],
            'note'         => $dryRun
                ? 'Dry-run delivery mode active: transactional messages are queued and simulated locally without external SMTP network requests.'
                : 'Live delivery mode enabled with transactional mail queue.',
        ];
    }

    /**
     * Retrieve paginated tenant deliveries.
     */
    public function getTenantDeliveries(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $clampedPerPage = max(1, min(50, $perPage));
        $query = MailDeliveryRecord::forTenant($user->id)->latest('id');

        if (!empty($filters['status']) && in_array($filters['status'], ['queued', 'processing', 'sent', 'failed', 'cancelled'], true)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['message_type']) && in_array($filters['message_type'], self::MESSAGE_TYPES, true)) {
            $query->where('message_type', $filters['message_type']);
        }

        return $query->paginate($clampedPerPage);
    }

    /**
     * Retrieve paginated administrative deliveries (with optional tenant filtering).
     */
    public function getAdminDeliveries(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $clampedPerPage = max(1, min(50, $perPage));
        $query = MailDeliveryRecord::query()->with('tenant:id,name,email')->latest('id');

        if (!empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (!empty($filters['status']) && in_array($filters['status'], ['queued', 'processing', 'sent', 'failed', 'cancelled'], true)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['message_type']) && in_array($filters['message_type'], self::MESSAGE_TYPES, true)) {
            $query->where('message_type', $filters['message_type']);
        }

        return $query->paginate($clampedPerPage);
    }

    /**
     * Validate payload requirements per message type.
     */
    protected function validatePayload(string $messageType, array $payload): void
    {
        switch ($messageType) {
            case 'account_verification':
                if (empty($payload['name']) && empty($payload['user_name'])) {
                    throw new InvalidPayloadException("Payload missing required field: 'name' or 'user_name' for account verification.");
                }
                if (empty($payload['verification_url']) && empty($payload['verification_code'])) {
                    throw new InvalidPayloadException("Payload requires either 'verification_url' or 'verification_code' for account verification.");
                }
                break;

            case 'password_reset':
                if (empty($payload['reset_url'])) {
                    throw new InvalidPayloadException("Payload missing required field: 'reset_url' for password reset.");
                }
                break;

            case 'billing_invoice':
                if (empty($payload['invoice_number'])) {
                    throw new InvalidPayloadException("Payload missing required field: 'invoice_number' for billing invoice.");
                }
                if (!isset($payload['amount'])) {
                    throw new InvalidPayloadException("Payload missing required field: 'amount' for billing invoice.");
                }
                break;

            case 'security_alert':
                if (empty($payload['alert_title']) && empty($payload['title'])) {
                    throw new InvalidPayloadException("Payload missing required field: 'alert_title' or 'title' for security alert.");
                }
                break;
        }
    }

    /**
     * Synthesize default subject line if omitted.
     */
    protected function getDefaultSubject(string $messageType, array $payload): string
    {
        return match ($messageType) {
            'account_verification' => 'Verify your EmailSaaS account',
            'password_reset'       => 'Reset your EmailSaaS password',
            'billing_invoice'      => 'New Invoice from EmailSaaS #' . ($payload['invoice_number'] ?? ''),
            'security_alert'       => 'Security Alert: ' . ($payload['alert_title'] ?? 'Notice'),
            default                => 'Notification from EmailSaaS',
        };
    }

    /**
     * Recursively mask or remove sensitive credentials and authentication tokens from payload before storing.
     */
    public function scrubPayload(array $payload, int $depth = 0): array
    {
        if ($depth > 10) {
            return ['_truncated' => 'Max metadata nesting depth exceeded'];
        }

        $cleaned = [];
        foreach ($payload as $k => $v) {
            $keyStr = strtolower((string) $k);

            // Redact or mask sensitive keys
            if (in_array($keyStr, self::SENSITIVE_KEYS, true)) {
                $cleaned[$k] = '[REDACTED]';
                continue;
            }

            if (is_array($v)) {
                $cleaned[$k] = $this->scrubPayload($v, $depth + 1);
            } elseif (is_string($v)) {
                $cleaned[$k] = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            } else {
                $cleaned[$k] = $v;
            }
        }

        return $cleaned;
    }

    /**
     * Calculate exponential backoff with jitter in seconds.
     */
    public function calculateBackoffSeconds(int $attempt, int $baseBackoff = 60, int $maxBackoff = 900): int
    {
        $jitter = rand(1, 10);
        return (int) min($maxBackoff, ($baseBackoff * pow(2, max(0, $attempt - 1))) + $jitter);
    }
}
