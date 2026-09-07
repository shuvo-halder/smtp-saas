<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'description',
        'max_domains', 'max_mailboxes_per_domain',
        'storage_mb_per_mailbox', 'max_aliases_per_domain',
        'daily_outbound_recipients', 'mailbox_daily_outbound_recipients',
        'price_monthly', 'price_yearly',
        'features', 'is_active', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'features'    => 'array',
            'is_active'   => 'boolean',
            'is_featured' => 'boolean',
            'daily_outbound_recipients' => 'integer',
            'mailbox_daily_outbound_recipients' => 'integer',
            'price_monthly' => 'decimal:2',
            'price_yearly'  => 'decimal:2',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Yearly discount percentage */
    public function yearlyDiscount(): int
    {
        if ($this->price_monthly <= 0) return 0;
        $annualMonthly = $this->price_monthly * 12;
        return (int) round((($annualMonthly - $this->price_yearly) / $annualMonthly) * 100);
    }

    public function maxDomainsLabel(): string
    {
        return $this->max_domains === -1 ? 'Unlimited' : (string) $this->max_domains;
    }

    public function maxMailboxesLabel(): string
    {
        return $this->max_mailboxes_per_domain === -1 ? 'Unlimited' : (string) $this->max_mailboxes_per_domain;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
