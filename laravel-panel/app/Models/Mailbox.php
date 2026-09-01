<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'domain_id', 'local_part', 'email',
        'display_name', 'quota_mb', 'is_active',
        'is_catchall', 'server_user_id', 'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'     => 'boolean',
            'is_catchall'   => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
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
}

class EmailAlias extends Model
{
    protected $fillable = ['domain_id', 'source_email', 'destination_email', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
