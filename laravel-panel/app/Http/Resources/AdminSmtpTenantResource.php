<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSmtpTenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'is_subscription_active' => $this->isSubscriptionActive(),
            'plan_name' => $this->plan?->name ?? 'No Plan',
            'daily_quota' => $this->plan?->daily_outbound_recipients ?? -1,
            'mailbox_daily_quota' => $this->plan?->mailbox_daily_outbound_recipients ?? -1,
            'domains_count' => $this->domains_count ?? $this->domains()->count(),
            'mailboxes_count' => $this->mailboxes_count ?? $this->totalMailboxes(),
            'telemetry_available' => $this->telemetry_available ?? true,
            'today_recipients' => $this->today_recipients ?? null,
            'today_hard_bounces' => $this->today_hard_bounces ?? null,
            'today_soft_bounces' => $this->today_soft_bounces ?? null,
            'bounce_rate' => $this->bounce_rate ?? null,
            'abuse_status' => $this->abuse_status ?? 'healthy',
            'active_alerts' => $this->active_alerts ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
