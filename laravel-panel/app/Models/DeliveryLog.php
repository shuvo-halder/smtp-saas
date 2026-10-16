<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class DeliveryLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'domain_id',
        'mailbox_id',
        'sender_email',
        'recipient_email',
        'queue_id',
        'message_id',
        'status',
        'dsn_code',
        'smtp_code',
        'response_text',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'smtp_code'   => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status) || $status === 'all') {
            return $query;
        }

        return $query->where('status', strtolower($status));
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $term = '%' . trim($search) . '%';
        return $query->where(function (Builder $q) use ($term) {
            $q->where('recipient_email', 'like', $term)
              ->orWhere('sender_email', 'like', $term)
              ->orWhere('queue_id', 'like', $term);
        });
    }

    public function scopeDateBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        if (!empty($from)) {
            $query->where('occurred_at', '>=', $from);
        }

        if (!empty($to)) {
            $query->where('occurred_at', '<=', $to);
        }

        return $query;
    }
}
