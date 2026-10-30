<?php

namespace App\Services;

use App\Exceptions\MailDelivery\InvalidDeliveryStateException;
use App\Exceptions\MailDelivery\InvalidPayloadException;
use App\Exceptions\MailDelivery\InvalidRecipientException;
use App\Exceptions\MailDelivery\RateLimitExceededException;
use App\Exceptions\MailDelivery\UnauthorizedRecipientException;
use App\Exceptions\MailDelivery\UnsupportedMessageTypeException;
use App\Jobs\ProcessMailDeliveryJob;
use App\Mail\TransactionalMail;
use App\Models\MailDeliveryRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

        // 5. Tenant Context & Rate Limit Validation
        if ($tenantId !== null) {
            $tenant = User::find($tenantId);
            if (!$tenant) {
                throw new UnauthorizedRecipientException("Tenant ID {$tenantId} does not exist.");
            }

            $rateLimit = (int) config('mail_delivery.tenant_rate_limit_per_minute', 60);
            $rateKey = "mail_delivery_rate:{$tenantId}";
            if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
                $seconds = RateLimiter::availableIn($rateKey);
                throw new RateLimitExceededException(
                    "Tenant {$tenantId} transactional mail enqueue rate limit exceeded. Retry in {$seconds} seconds."
                );
            }
            RateLimiter::hit($rateKey, 60);
        }

        // Default Subject if empty
        if (empty($subject)) {
            $subject = $this->getDefaultSubject($messageType, $payload);
        }
        $subject = strip_tags($subject);

        // 6. Sensitive Data Scrubbing
        $sanitizedPayload = $this->scrubPayload($payload);

        $dryRun = (bool) config('mail_delivery.dry_run', true);

        // 7. Atomic Idempotency / Deduplication Handling
        if ($idempotencyKey !== null) {
            $lockKey = 'mail_delivery_idemp:' . md5(($tenantId ?? 'global') . ':' . $idempotencyKey);
            $lock = Cache::lock($lockKey, 5);

            try {
                $lock->block(3);

                $existing = MailDeliveryRecord::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->when($tenantId !== null, fn($q) => $q->where('tenant_id', $tenantId))
                    ->latest('id')
                    ->first();

                if ($existing !== null && in_array($existing->status, ['queued', 'processing', 'sent'], true)) {
                    Log::info("MailDeliveryService: Idempotent duplicate detected for key {$idempotencyKey}. Returning existing record #{$existing->id}.");
                    return $existing;
                }

                $record = MailDeliveryRecord::create([
                    'uuid'            => (string) Str::uuid(),
                    'tenant_id'       => $tenantId,
                    'recipient_email' => $recipientEmail,
                    'message_type'    => $messageType,
                    'template_id'     => $templateId,
                    'subject'         => $subject,
                    'payload'         => $sanitizedPayload,
                    'status'          => MailDeliveryRecord::STATUS_QUEUED,
                    'attempts'        => 0,
                    'max_attempts'    => $maxAttempts,
                    'idempotency_key' => $idempotencyKey,
                    'is_dry_run'      => $dryRun,
                ]);

                ProcessMailDeliveryJob::dispatch($record->id);

                return $record;
            } finally {
                optional($lock)->release();
            }
        }

        // 8. Create Delivery Record (Non-idempotent path)
        $record = MailDeliveryRecord::create([
            'uuid'            => (string) Str::uuid(),
            'tenant_id'       => $tenantId,
            'recipient_email' => $recipientEmail,
            'message_type'    => $messageType,
            'template_id'     => $templateId,
            'subject'         => $subject,
            'payload'         => $sanitizedPayload,
            'status'          => MailDeliveryRecord::STATUS_QUEUED,
            'attempts'        => 0,
            'max_attempts'    => $maxAttempts,
            'idempotency_key' => null,
            'is_dry_run'      => $dryRun,
        ]);

        // 9. Dispatch Queue Job
        ProcessMailDeliveryJob::dispatch($record->id);

        return $record;
    }

    /**
     * Retry an eligible failed or cancelled mail delivery record.
     * Idempotent: returns existing record unchanged if already queued.
     * Throws InvalidDeliveryStateException if record is sent or actively processing.
     *
     * @throws InvalidDeliveryStateException
     */
    public function retry(int $recordId, ?User $actor = null): MailDeliveryRecord
    {
        $query = MailDeliveryRecord::query();

        // Enforce tenant isolation if actor is a tenant
        if ($actor && $actor->role === 'tenant') {
            $query->forTenant($actor->id);
        }

        $record = $query->findOrFail($recordId);

        // Strict state transition validations
        if ($record->isSent()) {
            throw new InvalidDeliveryStateException("Cannot retry a message that has already been delivered (#{$record->id}).");
        }

        if ($record->isProcessing()) {
            throw new InvalidDeliveryStateException("Cannot retry a message that is actively being processed by a worker (#{$record->id}).");
        }

        if ($record->isQueued()) {
            return $record;
        }

        // Atomic conditional transition: only from failed or cancelled
        $affected = MailDeliveryRecord::query()
            ->when($actor && $actor->role === 'tenant', fn($q) => $q->forTenant($actor->id))
            ->where('id', $recordId)
            ->whereIn('status', [MailDeliveryRecord::STATUS_FAILED, MailDeliveryRecord::STATUS_CANCELLED])
            ->update([
                'status'            => MailDeliveryRecord::STATUS_QUEUED,
                'attempts'          => 0,
                'failed_at'         => null,
                'cancelled_at'      => null,
                'last_error'        => null,
                'last_attempted_at' => null,
            ]);

        if ($affected > 0) {
            ProcessMailDeliveryJob::dispatch($record->id);
            Log::info("MailDeliveryService: Record #{$record->id} manually retried by " . ($actor ? $actor->email : 'system'));
        }

        return $record->fresh();
    }

    /**
     * Cancel a pending queued mail delivery record.
     * Idempotent: returns existing record if already cancelled or failed.
     * Throws InvalidDeliveryStateException if record is sent or actively processing.
     *
     * @throws InvalidDeliveryStateException
     */
    public function cancel(int $recordId, ?User $actor = null): MailDeliveryRecord
    {
        $query = MailDeliveryRecord::query();

        if ($actor && $actor->role === 'tenant') {
            $query->forTenant($actor->id);
        }

        $record = $query->findOrFail($recordId);

        // Cannot cancel already delivered messages
        if ($record->isSent()) {
            throw new InvalidDeliveryStateException("Cannot cancel an already delivered message (#{$record->id}).");
        }

        // Cannot cancel in-flight message actively in transport
        if ($record->isProcessing()) {
            throw new InvalidDeliveryStateException("Cannot cancel a message that is actively in-flight with a worker (#{$record->id}).");
        }

        // Idempotency: Already cancelled or failed
        if ($record->isCancelled() || $record->isFailed()) {
            return $record;
        }

        // Atomic transition from queued only
        $affected = MailDeliveryRecord::query()
            ->when($actor && $actor->role === 'tenant', fn($q) => $q->forTenant($actor->id))
            ->where('id', $recordId)
            ->where('status', MailDeliveryRecord::STATUS_QUEUED)
            ->update([
                'status'       => MailDeliveryRecord::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

        if ($affected > 0) {
            Log::info("MailDeliveryService: Record #{$record->id} cancelled by " . ($actor ? $actor->email : 'system'));
        }

        return $record->fresh();
    }

    /**
     * Recover records stuck in 'processing' status due to worker interruption or crash.
     * Uses atomic conditional updates ensuring active workers are not interrupted.
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

        $cutoff = Carbon::now()->subSeconds($staleSeconds);

        foreach ($staleRecords as $record) {
            $newAttempts = $record->attempts + 1;
            $maxAttempts = $record->max_attempts ?: (int) config('mail_delivery.default_max_attempts', 3);
            $isDeadLetter = $newAttempts >= $maxAttempts;

            $updateData = $isDeadLetter ? [
                'attempts'   => $newAttempts,
                'status'     => MailDeliveryRecord::STATUS_FAILED,
                'failed_at'  => now(),
                'last_error' => "Worker timeout / crash beyond {$staleSeconds}s threshold. Max attempts reached.",
            ] : [
                'attempts'   => $newAttempts,
                'status'     => MailDeliveryRecord::STATUS_QUEUED,
                'last_error' => "Recovered from interrupted processing state (attempt {$newAttempts}/{$maxAttempts}).",
            ];

            // Atomic conditional update ensuring record is STILL in processing and last_attempted_at is STILL stale
            $affected = MailDeliveryRecord::where('id', $record->id)
                ->where('status', MailDeliveryRecord::STATUS_PROCESSING)
                ->where(function ($q) use ($cutoff) {
                    $q->where('last_attempted_at', '<', $cutoff)
                      ->orWhere(function ($sub) use ($cutoff) {
                          $sub->whereNull('last_attempted_at')
                              ->where('updated_at', '<', $cutoff);
                      });
                })
                ->update($updateData);

            if ($affected > 0) {
                if ($isDeadLetter) {
                    $deadLettered++;
                    Log::error("MailDeliveryService: Stale record #{$record->id} dead-lettered after {$newAttempts} attempts.");
                } else {
                    $recovered++;
                    ProcessMailDeliveryJob::dispatch($record->id);
                    Log::warning("MailDeliveryService: Stale record #{$record->id} recovered and re-queued (attempt {$newAttempts}/{$maxAttempts}).");
                }
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
        $configHealth = $this->validateConfiguration();

        $status = 'HEALTHY';
        if ($staleProcessing > 0 || $deadLetter > 5 || $dbFailedJobs24h > 10 || !$configHealth['is_valid']) {
            $status = 'DEGRADED';
        }

        return [
            'status'               => $status,
            'timestamp'            => now()->toIso8601String(),
            'dry_run_mode'         => $dryRun,
            'queue_driver'         => $queueDriver,
            'configuration_health' => $configHealth,
            'metrics'              => [
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
                if (!empty($payload['verification_url'])) {
                    $this->validateSafeUrl($payload['verification_url'], 'verification_url');
                }
                break;

            case 'password_reset':
                if (empty($payload['reset_url'])) {
                    throw new InvalidPayloadException("Payload missing required field: 'reset_url' for password reset.");
                }
                $this->validateSafeUrl($payload['reset_url'], 'reset_url');
                break;

            case 'billing_invoice':
                if (empty($payload['invoice_number'])) {
                    throw new InvalidPayloadException("Payload missing required field: 'invoice_number' for billing invoice.");
                }
                if (!isset($payload['amount'])) {
                    throw new InvalidPayloadException("Payload missing required field: 'amount' for billing invoice.");
                }
                if (!empty($payload['invoice_url'])) {
                    $this->validateSafeUrl($payload['invoice_url'], 'invoice_url');
                }
                break;

            case 'security_alert':
                if (empty($payload['alert_title']) && empty($payload['title'])) {
                    throw new InvalidPayloadException("Payload missing required field: 'alert_title' or 'title' for security alert.");
                }
                if (!empty($payload['action_url'])) {
                    $this->validateSafeUrl($payload['action_url'], 'action_url');
                }
                break;
        }
    }

    /**
     * Validate that a URL is well-formed and restricted to safe HTTP/HTTPS schemes.
     *
     * @throws InvalidPayloadException
     */
    public function validateSafeUrl(string $url, string $fieldName): void
    {
        $trimmed = trim($url);
        if (!filter_var($trimmed, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $trimmed)) {
            throw new InvalidPayloadException("Payload field '{$fieldName}' must be a valid HTTP or HTTPS URL.");
        }
    }

    /**
     * Validate queue and mail delivery configuration settings.
     *
     * @return array{is_valid: bool, issues: array<string>}
     */
    public function validateConfiguration(): array
    {
        $issues = [];

        $defaultMaxAttempts = (int) config('mail_delivery.default_max_attempts', 3);
        $baseBackoff = (int) config('mail_delivery.base_backoff_seconds', 60);
        $maxBackoff = (int) config('mail_delivery.max_backoff_seconds', 900);
        $staleThreshold = (int) config('mail_delivery.stale_threshold_seconds', 900);
        $jobTimeout = (int) config('mail_delivery.job_timeout_seconds', 60);
        $workerLease = (int) config('mail_delivery.worker_lease_seconds', 120);

        if ($defaultMaxAttempts < 1 || $defaultMaxAttempts > 10) {
            $issues[] = "default_max_attempts must be between 1 and 10 (current: {$defaultMaxAttempts}).";
        }

        if ($baseBackoff < 1) {
            $issues[] = "base_backoff_seconds must be at least 1 (current: {$baseBackoff}).";
        }

        if ($maxBackoff < $baseBackoff) {
            $issues[] = "max_backoff_seconds ({$maxBackoff}) must be greater than or equal to base_backoff_seconds ({$baseBackoff}).";
        }

        if ($jobTimeout < 5) {
            $issues[] = "job_timeout_seconds must be at least 5 (current: {$jobTimeout}).";
        }

        if ($workerLease < $jobTimeout) {
            $issues[] = "worker_lease_seconds ({$workerLease}) must be greater than job_timeout_seconds ({$jobTimeout}).";
        }

        if ($staleThreshold <= $workerLease) {
            $issues[] = "stale_threshold_seconds ({$staleThreshold}) must be greater than worker_lease_seconds ({$workerLease}).";
        }

        $queueDriver = config('queue.default', 'database');
        if ($queueDriver === 'database') {
            $retryAfter = (int) config('queue.connections.database.retry_after', 90);
            if ($retryAfter <= $jobTimeout) {
                $issues[] = "queue.connections.database.retry_after ({$retryAfter}) must be greater than job_timeout_seconds ({$jobTimeout}).";
            }
        }

        return [
            'is_valid' => empty($issues),
            'issues'   => $issues,
        ];
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
