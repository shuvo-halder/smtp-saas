<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'phone',
        'company_name', 'address', 'plan_id',
        'status', 'plan_expires_at', 'is_admin',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'plan_expires_at'   => 'datetime',
            'password'          => 'hashed',
            'is_admin'          => 'boolean',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest();
    }

    // ─── Computed Properties ────────────────────────────────────────────────────

    public function activeDomains(): HasMany
    {
        return $this->domains()->where('status', 'active');
    }

    public function totalMailboxes(): int
    {
        return $this->domains()
            ->withCount(['mailboxes' => fn($q) => $q->where('is_active', true)])
            ->get()
            ->sum('mailboxes_count');
    }

    public function isSubscriptionActive(): bool
    {
        return $this->status === 'active'
            && ($this->plan_expires_at === null || $this->plan_expires_at->isFuture());
    }

    public function canAddDomain(): bool
    {
        if (! $this->plan) return false;
        if ($this->plan->max_domains === -1) return true;
        return $this->domains()->count() < $this->plan->max_domains;
    }

    public function canAddMailbox(Domain $domain): bool
    {
        if (! $this->plan) return false;
        if ($this->plan->max_mailboxes_per_domain === -1) return true;
        return $domain->mailboxes()->count() < $this->plan->max_mailboxes_per_domain;
    }
}
