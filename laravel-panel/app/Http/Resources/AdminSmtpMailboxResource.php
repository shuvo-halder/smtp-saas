<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSmtpMailboxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $domain = $this->domain;
        $tenant = $domain?->user;

        $canBeEnabled = $domain !== null
            && $domain->status === 'active'
            && $tenant !== null
            && $tenant->status === 'active'
            && $tenant->isSubscriptionActive();

        return [
            'id' => $this->id,
            'local_part' => $this->local_part,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'domain_id' => $this->domain_id,
            'domain_name' => $domain?->domain_name ?? 'Unknown',
            'tenant_id' => $tenant?->id ?? null,
            'tenant_name' => $tenant?->name ?? 'Unknown',
            'is_active' => (bool) $this->is_active,
            'parent_domain_status' => $domain?->status ?? 'unknown',
            'parent_tenant_status' => $tenant?->status ?? 'unknown',
            'parent_subscription_active' => $tenant ? $tenant->isSubscriptionActive() : false,
            'can_be_enabled' => $canBeEnabled,
            'telemetry_available' => $this->telemetry_available ?? true,
            'today_recipients' => $this->today_recipients ?? null,
            'today_hard_bounces' => $this->today_hard_bounces ?? null,
            'today_soft_bounces' => $this->today_soft_bounces ?? null,
            'consecutive_hard_bounces' => $this->consecutive_hard_bounces ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
