<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MailboxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'domain_name' => $this->domain?->domain_name,
            'local_part' => $this->local_part,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'quota_mb' => $this->quota_mb,
            'quota_formatted' => $this->quotaFormatted(),
            'is_active' => $this->is_active,
            'is_catchall' => $this->is_catchall,
            'is_forwarding_enabled' => (bool) $this->is_forwarding_enabled,
            'auto_reply_enabled' => (bool) $this->auto_reply_enabled,
            'webmail_url' => $this->webmailUrl(),
            'last_login_at' => $this->last_login_at,
            'storage' => app(\App\Services\MailboxStorageService::class)->getMailboxStorage($this->resource),
            'created_at' => $this->created_at,
        ];
    }
}
