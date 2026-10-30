<?php

namespace App\Models;

use App\Exceptions\MailDelivery\InvalidDeliveryStateException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailDeliveryRecord extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Permitted state transitions in the transactional mail delivery lifecycle.
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_QUEUED => [
            self::STATUS_PROCESSING,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_PROCESSING => [
            self::STATUS_SENT,
            self::STATUS_FAILED,
            self::STATUS_QUEUED,
        ],
        self::STATUS_FAILED => [
            self::STATUS_QUEUED,
        ],
        self::STATUS_CANCELLED => [
            self::STATUS_QUEUED,
        ],
        self::STATUS_SENT => [],
    ];

    protected $table = 'mail_deliveries';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'recipient_email',
        'message_type',
        'template_id',
        'subject',
        'payload',
        'status',
        'attempts',
        'max_attempts',
        'idempotency_key',
        'last_attempted_at',
        'sent_at',
        'failed_at',
        'cancelled_at',
        'last_error',
        'is_dry_run',
    ];

    protected function casts(): array
    {
        return [
            'payload'           => 'array',
            'is_dry_run'        => 'boolean',
            'attempts'          => 'integer',
            'max_attempts'      => 'integer',
            'last_attempted_at' => 'datetime',
            'sent_at'           => 'datetime',
            'failed_at'         => 'datetime',
            'cancelled_at'      => 'datetime',
        ];
    }

    /**
     * Owning tenant user.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', 'queued');
    }

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', 'processing');
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeStaleProcessing(Builder $query, int $staleSeconds = 900): Builder
    {
        $cutoff = Carbon::now()->subSeconds($staleSeconds);

        return $query->where('status', 'processing')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_attempted_at', '<', $cutoff)
                  ->orWhere(function ($sub) use ($cutoff) {
                      $sub->whereNull('last_attempted_at')
                          ->where('updated_at', '<', $cutoff);
                  });
            });
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['sent', 'failed', 'cancelled'], true);
    }

    public function canRetry(): bool
    {
        return in_array($this->status, ['failed', 'cancelled'], true);
    }

    /**
     * Check whether a state transition from current status to target status is valid.
     */
    public function canTransitionTo(string $targetStatus): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];
        return in_array($targetStatus, $allowed, true);
    }

    /**
     * Perform an explicit, validated state transition.
     *
     * @throws InvalidDeliveryStateException
     */
    public function transitionTo(string $targetStatus, array $attributes = []): bool
    {
        if (!$this->canTransitionTo($targetStatus)) {
            throw new InvalidDeliveryStateException(
                "Invalid delivery state transition from '{$this->status}' to '{$targetStatus}' for record #{$this->id}."
            );
        }

        return $this->update(array_merge($attributes, ['status' => $targetStatus]));
    }

    /**
     * Record a failed attempt and transition status accordingly.
     *
     * @throws InvalidDeliveryStateException
     */
    public function markFailedAttempt(string $errorMessage, bool $isTransient = true): void
    {
        if ($this->isSent()) {
            throw new InvalidDeliveryStateException("Cannot mark attempt failed on already delivered record #{$this->id}.");
        }

        $newAttempts = $this->attempts + 1;
        $maxAttempts = $this->max_attempts ?: 3;
        $targetStatus = (!$isTransient || $newAttempts >= $maxAttempts) ? self::STATUS_FAILED : self::STATUS_QUEUED;

        if (!$this->canTransitionTo($targetStatus)) {
            throw new InvalidDeliveryStateException(
                "Invalid state transition from '{$this->status}' to '{$targetStatus}' during failure handling."
            );
        }

        if ($targetStatus === self::STATUS_FAILED) {
            $this->update([
                'attempts' => $newAttempts,
                'status' => self::STATUS_FAILED,
                'failed_at' => Carbon::now(),
                'last_error' => $errorMessage,
            ]);
        } else {
            $this->update([
                'attempts' => $newAttempts,
                'status' => self::STATUS_QUEUED,
                'last_error' => $errorMessage,
            ]);
        }
    }
}
