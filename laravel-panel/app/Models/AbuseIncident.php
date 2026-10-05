<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AbuseIncident extends Model
{
    protected $table = 'abuse_incidents';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'tenant_email',
        'domain_id',
        'domain_name',
        'mailbox_id',
        'mailbox_email',
        'incident_type',
        'severity',
        'status',
        'detection_source',
        'summary',
        'threshold_value',
        'observed_value',
        'evidence',
        'idempotency_key',
        'occurred_at',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'evidence'    => 'array',
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AbuseIncident $incident) {
            if (empty($incident->uuid)) {
                $incident->uuid = (string) Str::uuid();
            }
            if (empty($incident->occurred_at)) {
                $incident->occurred_at = now();
            }
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    /**
     * Tenant associated with this abuse incident.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * Domain associated with this abuse incident.
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    /**
     * Mailbox associated with this abuse incident.
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
    }

    /**
     * Administrator who resolved this abuse incident.
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ─── Query Scopes ───────────────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', 'resolved');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('incident_type', $type);
    }

    public function scopeSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    /**
     * Retrieve the model for a bound value (supports both id and uuid).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (is_numeric($value)) {
            return $this->where('id', $value)->firstOrFail();
        }
        return $this->where('uuid', $value)->firstOrFail();
    }
}
