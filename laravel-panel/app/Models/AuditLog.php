<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /**
     * Application-level append-only audit records.
     * Only created_at is tracked; updated_at is disabled.
     */
    const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'actor_user_id',
        'actor_email',
        'action',
        'entity_type',
        'entity_id',
        'before_state',
        'after_state',
        'reason',
        'ip_address',
        'user_agent',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state'  => 'array',
            'created_at'   => 'datetime',
        ];
    }

    /**
     * Administrator user who triggered the action.
     * Nullable to accommodate system actions or purged user accounts.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
