<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class TenantNotification extends Model
{
    use HasFactory;

    protected $table = 'tenant_notifications';

    protected $fillable = [
        'tenant_id',
        'category',
        'severity',
        'title',
        'message',
        'action_url',
        'deduplication_key',
        'data',
        'read_at',
        'dismissed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'data'         => 'array',
            'read_at'      => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /**
     * Owning tenant user.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * Check if notification is read.
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Check if notification is dismissed.
     */
    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    /**
     * Mark notification as read idempotently.
     */
    public function markAsRead(): bool
    {
        if ($this->isRead()) {
            return true;
        }

        return $this->update(['read_at' => now()]);
    }

    /**
     * Mark notification as unread idempotently.
     */
    public function markAsUnread(): bool
    {
        if (!$this->isRead()) {
            return true;
        }

        return $this->update(['read_at' => null]);
    }

    /**
     * Dismiss notification idempotently.
     */
    public function dismiss(): bool
    {
        if ($this->isDismissed()) {
            return true;
        }

        return $this->update(['dismissed_at' => now()]);
    }

    /**
     * Restore a dismissed notification idempotently.
     */
    public function restore(): bool
    {
        if (!$this->isDismissed()) {
            return true;
        }

        return $this->update(['dismissed_at' => null]);
    }

    /**
     * Scope to tenant.
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to unread notifications.
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope to read notifications.
     */
    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope to active (non-dismissed) notifications.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    /**
     * Scope to dismissed notifications.
     */
    public function scopeDismissed(Builder $query): Builder
    {
        return $query->whereNotNull('dismissed_at');
    }

    /**
     * Scope by category.
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope by severity.
     */
    public function scopeSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }
}
