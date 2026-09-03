<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company_name' => $this->company_name,
            'status' => $this->status,
            'is_admin' => $this->is_admin,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'plan_expires_at' => $this->plan_expires_at,
            'domains_count' => $this->whenCounted('domains'),
            'mailboxes_count' => $this->whenCounted('mailboxes'),
            'domains' => DomainResource::collection($this->whenLoaded('domains')),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            'created_at' => $this->created_at,
        ];
    }
}
