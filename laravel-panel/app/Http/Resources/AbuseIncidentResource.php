<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AbuseIncidentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'tenant_id'        => $this->tenant_id,
            'tenant_email'     => $this->tenant_email ?? $this->tenant?->email,
            'domain_id'        => $this->domain_id,
            'domain_name'      => $this->domain_name ?? $this->domain?->domain_name,
            'mailbox_id'       => $this->mailbox_id,
            'mailbox_email'    => $this->mailbox_email ?? $this->mailbox?->email,
            'incident_type'    => $this->incident_type,
            'severity'         => $this->severity,
            'status'           => $this->status,
            'detection_source' => $this->detection_source,
            'summary'          => $this->summary,
            'threshold_value'  => $this->threshold_value,
            'observed_value'   => $this->observed_value,
            'evidence'         => $this->evidence ?? [],
            'idempotency_key'  => $this->idempotency_key,
            'occurred_at'      => $this->occurred_at?->toIso8601String(),
            'resolved_at'      => $this->resolved_at?->toIso8601String(),
            'resolved_by'      => $this->resolved_by,
            'resolver_name'    => $this->resolver?->name,
            'resolution_notes' => $this->resolution_notes,
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
