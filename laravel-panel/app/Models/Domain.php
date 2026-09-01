<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'domain_name', 'status',
        'mx_verified', 'spf_verified', 'dkim_verified', 'dmarc_verified',
        'dkim_public_key', 'dkim_selector', 'server_domain_id',
        'last_dns_check_at', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'mx_verified'       => 'boolean',
            'spf_verified'      => 'boolean',
            'dkim_verified'     => 'boolean',
            'dmarc_verified'    => 'boolean',
            'last_dns_check_at' => 'datetime',
            'activated_at'      => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(EmailAlias::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function isFullyVerified(): bool
    {
        return $this->mx_verified && $this->spf_verified && $this->dkim_verified;
    }

    public function verificationStatus(): array
    {
        return [
            'mx'    => $this->mx_verified,
            'spf'   => $this->spf_verified,
            'dkim'  => $this->dkim_verified,
            'dmarc' => $this->dmarc_verified,
        ];
    }

    /**
     * Required DNS records for this domain
     */
    public function requiredDnsRecords(): array
    {
        $mailServer = config('app.mail_server_host', 'mail.yourdomain.com');
        $dkimSelector = $this->dkim_selector ?? 'default';

        return [
            'mx'   => [
                'type'     => 'MX',
                'host'     => '@',
                'value'    => "10 {$mailServer}",
                'verified' => $this->mx_verified,
            ],
            'spf'  => [
                'type'     => 'TXT',
                'host'     => '@',
                'value'    => "v=spf1 mx a:{$mailServer} ~all",
                'verified' => $this->spf_verified,
            ],
            'dkim' => [
                'type'     => 'TXT',
                'host'     => "{$dkimSelector}._domainkey",
                'value'    => $this->dkim_public_key ?? 'Generating...',
                'verified' => $this->dkim_verified,
            ],
            'dmarc' => [
                'type'     => 'TXT',
                'host'     => '_dmarc',
                'value'    => "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$this->domain_name}",
                'verified' => $this->dmarc_verified,
            ],
        ];
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
