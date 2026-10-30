<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MailDeliveryRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'tenant_id'         => $this->tenant_id,
            'recipient_email'   => $this->recipient_email,
            'message_type'      => $this->message_type,
            'template_id'       => $this->template_id,
            'subject'           => $this->subject,
            'status'            => $this->status,
            'attempts'          => $this->attempts,
            'max_attempts'      => $this->max_attempts,
            'idempotency_key'   => $this->idempotency_key,
            'is_dry_run'        => (bool) $this->is_dry_run,
            'last_attempted_at' => $this->last_attempted_at?->toIso8601String(),
            'sent_at'           => $this->sent_at?->toIso8601String(),
            'failed_at'         => $this->failed_at?->toIso8601String(),
            'cancelled_at'      => $this->cancelled_at?->toIso8601String(),
            'last_error'        => $this->last_error,
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
