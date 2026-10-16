<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'domain_id', 'local_part', 'email', 'password',
        'display_name', 'quota_mb', 'is_active',
        'is_catchall', 'server_user_id', 'last_login_at',
        'is_forwarding_enabled', 'forwarding_destinations', 'forwarding_keep_copy',
        'auto_reply_enabled', 'auto_reply_subject', 'auto_reply_body',
        'auto_reply_start_at', 'auto_reply_end_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_active'             => 'boolean',
            'is_catchall'           => 'boolean',
            'is_forwarding_enabled' => 'boolean',
            'forwarding_keep_copy'  => 'boolean',
            'auto_reply_enabled'    => 'boolean',
            'auto_reply_start_at'   => 'datetime',
            'auto_reply_end_at'     => 'datetime',
            'last_login_at'         => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function deliveryLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeliveryLog::class, 'mailbox_id')->latest('occurred_at');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /** Quota in human-readable format */
    public function quotaFormatted(): string
    {
        if ($this->quota_mb >= 1024) {
            return round($this->quota_mb / 1024, 1) . ' GB';
        }
        return $this->quota_mb . ' MB';
    }

    /** Webmail URL for this mailbox */
    public function webmailUrl(): string
    {
        return config('app.roundcube_url', 'https://webmail.yourdomain.com')
            . '/?_user=' . urlencode($this->email);
    }

    /** Whether this mailbox is currently the designated catch-all */
    public function isCatchAll(): bool
    {
        return (bool) $this->is_catchall;
    }

    /**
     * Get clean array of forwarding destinations
     * @return string[]
     */
    public function getForwardingDestinationsList(): array
    {
        if (empty($this->forwarding_destinations)) {
            return [];
        }

        $parts = preg_split('/[\s,]+/', (string) $this->forwarding_destinations, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('strtolower', $parts)));
    }

    /**
     * Set forwarding destinations from array of emails
     * @param string[] $destinations
     */
    public function setForwardingDestinationsList(array $destinations): void
    {
        $clean = array_values(array_unique(array_map(fn($e) => strtolower(trim($e)), $destinations)));
        $this->forwarding_destinations = !empty($clean) ? implode(', ', $clean) : null;
    }

    /**
     * Check if auto-reply is currently active based on schedule
     */
    public function isAutoReplyActive(): bool
    {
        if (!$this->auto_reply_enabled) {
            return false;
        }

        $now = now();

        if ($this->auto_reply_start_at && $now->lt($this->auto_reply_start_at)) {
            return false;
        }

        if ($this->auto_reply_end_at && $now->gt($this->auto_reply_end_at)) {
            return false;
        }

        return true;
    }

    /**
     * Determine human-readable auto-reply schedule status
     */
    public function autoReplyScheduleStatus(): string
    {
        if (!$this->auto_reply_enabled) {
            return 'disabled';
        }

        $now = now();

        if ($this->auto_reply_start_at && $now->lt($this->auto_reply_start_at)) {
            return 'scheduled';
        }

        if ($this->auto_reply_end_at && $now->gt($this->auto_reply_end_at)) {
            return 'expired';
        }

        return 'active';
    }
}
