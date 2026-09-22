<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'actor_user_id' => $this->actor_user_id,
            'actor_name'    => $this->actor?->name ?? 'System / Deleted User',
            'actor_email'   => $this->actor_email ?? $this->actor?->email ?? 'System',
            'action'        => $this->action,
            'entity_type'   => $this->entity_type,
            'entity_id'     => $this->entity_id,
            'before_state'  => $this->before_state,
            'after_state'   => $this->after_state,
            'reason'        => $this->reason,
            'ip_address'    => $this->ip_address,
            'user_agent'    => $this->user_agent,
            'request_id'    => $this->request_id,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
