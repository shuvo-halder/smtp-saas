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
        'catch_all_policy', 'catch_all_mailbox_id',
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

    public function catchAllMailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'catch_all_mailbox_id');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function isCatchAllEnabled(): bool
    {
        return in_array($this->catch_all_policy, ['mailbox', 'discard'], true);
    }

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
        $mailServer = config('mail.mail_server_host')
            ?: config('app.mail_server_host')
            ?: env('MAIL_SERVER_HOST')
            ?: env('SMTP_SERVER_HOST')
            ?: 'mail.mailsaas.com';

        $dkimSelector = $this->dkim_selector ?? 'default';
        $dkimValue = $this->dkim_public_key
            ? (str_starts_with($this->dkim_public_key, 'v=DKIM1') ? $this->dkim_public_key : "v=DKIM1; k=rsa; p={$this->dkim_public_key}")
            : 'v=DKIM1; k=rsa; p=<pending_generation>';

        return [
            [
                'id'          => 'mx',
                'type'        => 'MX',
                'host'        => '@',
                'value'       => "10 {$mailServer}",
                'priority'    => 10,
                'purpose'     => 'Inbound Mail Routing',
                'verified'    => (bool) $this->mx_verified,
                'status'      => $this->mx_verified ? 'VERIFIED' : 'EXPECTED',
                'is_required' => true,
            ],
            [
                'id'          => 'spf',
                'type'        => 'TXT',
                'host'        => '@',
                'value'       => "v=spf1 mx a:{$mailServer} ~all",
                'priority'    => null,
                'purpose'     => 'SPF Sender Authorization',
                'verified'    => (bool) $this->spf_verified,
                'status'      => $this->spf_verified ? 'VERIFIED' : 'EXPECTED',
                'is_required' => true,
            ],
            [
                'id'          => 'dkim',
                'type'        => 'TXT',
                'host'        => "{$dkimSelector}._domainkey",
                'value'       => $dkimValue,
                'priority'    => null,
                'purpose'     => 'DKIM Cryptographic Signature',
                'verified'    => (bool) $this->dkim_verified,
                'status'      => $this->dkim_verified ? 'VERIFIED' : ($this->dkim_public_key ? 'EXPECTED' : 'NOT_CONFIGURED'),
                'is_required' => true,
            ],
            [
                'id'          => 'dmarc',
                'type'        => 'TXT',
                'host'        => '_dmarc',
                'value'       => "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$this->domain_name}",
                'priority'    => null,
                'purpose'     => 'DMARC Policy & Reporting',
                'verified'    => (bool) $this->dmarc_verified,
                'status'      => $this->dmarc_verified ? 'VERIFIED' : 'EXPECTED',
                'is_required' => false,
            ],
            [
                'id'          => 'autodiscover',
                'type'        => 'CNAME',
                'host'        => 'autodiscover',
                'value'       => $mailServer,
                'priority'    => null,
                'purpose'     => 'Mail Client Auto-Configuration (Outlook)',
                'verified'    => false,
                'status'      => 'EXPECTED',
                'is_required' => false,
            ],
            [
                'id'          => 'autoconfig',
                'type'        => 'CNAME',
                'host'        => 'autoconfig',
                'value'       => $mailServer,
                'priority'    => null,
                'purpose'     => 'Mail Client Auto-Configuration (Thunderbird)',
                'verified'    => false,
                'status'      => 'EXPECTED',
                'is_required' => false,
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
