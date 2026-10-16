<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailAliasResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'domain_name' => $this->domain?->domain_name,
            'source_email' => $this->source_email,
            'destination_email' => $this->destination_email,
            'is_active' => (bool) $this->is_active,
            'forwarding_status' => 'configured',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
