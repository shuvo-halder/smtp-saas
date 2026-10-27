<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasRoles;

    /**
     * Spatie Permission Guard Name.
     * Locked to 'web' per RBAC-DEC-01.
     */
    protected string $guard_name = 'web';

    protected $fillable = [
        'name', 'email', 'password', 'phone',
        'company_name', 'address', 'plan_id',
        'status', 'plan_expires_at', 'is_admin',
        'notification_preferences',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'plan_expires_at'          => 'datetime',
            'password'                 => 'hashed',
            'is_admin'                 => 'boolean',
            'notification_preferences' => 'array',
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

    public function mailboxes(): HasManyThrough
    {
        return $this->hasManyThrough(Mailbox::class, Domain::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest();
    }

    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(DeliveryLog::class, 'tenant_id')->latest('occurred_at');
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

    public function aliases(): HasManyThrough
    {
        return $this->hasManyThrough(EmailAlias::class, Domain::class);
    }

    public function canAddAlias(Domain $domain): bool
    {
        if (! $this->plan) return false;
        if ($this->plan->max_aliases_per_domain === -1) return true;
        return $domain->aliases()->count() < $this->plan->max_aliases_per_domain;
    }

    public function tenantNotifications(): HasMany
    {
        return $this->hasMany(TenantNotification::class, 'tenant_id');
    }

    /**
     * Get tenant notification preferences with sensible defaults.
     */
    public function getNotificationPreferences(): array
    {
        $defaults = [
            'domain_dns'           => true,
            'security_abuse'       => true,
            'smtp_quota'           => true,
            'billing_subscription' => true,
            'mailbox_rule'         => true,
            'system'               => true,
        ];

        $stored = $this->notification_preferences;
        if (!is_array($stored)) {
            return $defaults;
        }

        $merged = array_merge($defaults, $stored);

        // Security invariants: security and billing alerts can never be muted
        $merged['security_abuse'] = true;
        $merged['billing_subscription'] = true;

        return $merged;
    }

    /**
     * Check if a specific notification category is enabled for the tenant.
     */
    public function isNotificationCategoryEnabled(string $category): bool
    {
        $prefs = $this->getNotificationPreferences();

        return (bool) ($prefs[$category] ?? true);
    }
}

