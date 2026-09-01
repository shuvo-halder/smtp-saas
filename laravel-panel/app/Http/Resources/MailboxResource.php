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
            'local_part' => $this->local_part,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'quota_mb' => $this->quota_mb,
            'quota_formatted' => $this->quotaFormatted(),
            'is_active' => $this->is_active,
            'is_catchall' => $this->is_catchall,
            'webmail_url' => $this->webmailUrl(),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
