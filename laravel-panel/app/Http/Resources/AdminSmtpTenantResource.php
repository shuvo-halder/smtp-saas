<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSmtpTenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dailyQuota = $this->plan?->daily_outbound_recipients ?? -1;
        $todayRecipients = $this->today_recipients ?? null;

        $remainingDailyQuota = null;
        $quotaUsagePercent = null;
        $isOverQuota = false;

        if ($dailyQuota > 0 && $todayRecipients !== null) {
            $remainingDailyQuota = max(0, $dailyQuota - $todayRecipients);
            $quotaUsagePercent = round(min(100, ($todayRecipients / $dailyQuota) * 100), 1);
            $isOverQuota = $todayRecipients > $dailyQuota;
        } elseif ($dailyQuota === -1) {
            $remainingDailyQuota = -1; // -1 denotes unlimited
            $quotaUsagePercent = null;
            $isOverQuota = false;
        }

        // Determine granular subscription state
        $subscriptionState = 'valid';
        if ($this->is_admin) {
            $subscriptionState = 'admin_exempt';
        } elseif ($this->status === 'suspended') {
            $subscriptionState = 'suspended';
        } elseif ($this->status === 'pending') {
            $subscriptionState = 'pending';
        } elseif ($this->plan_id === null) {
            $subscriptionState = 'no_plan';
        } elseif ($this->plan_expires_at !== null && $this->plan_expires_at->isPast()) {
            $subscriptionState = 'expired';
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'is_subscription_active' => $this->isSubscriptionActive(),
            'subscription_state' => $subscriptionState,
            'plan_name' => $this->plan?->name ?? 'No Plan',
            'daily_quota' => $dailyQuota,
            'remaining_daily_quota' => $remainingDailyQuota,
            'quota_usage_percent' => $quotaUsagePercent,
            'is_over_quota' => $isOverQuota,
            'mailbox_daily_quota' => $this->plan?->mailbox_daily_outbound_recipients ?? -1,
            'domains_count' => $this->domains_count ?? $this->domains()->count(),
            'mailboxes_count' => $this->mailboxes_count ?? $this->totalMailboxes(),
            'telemetry_available' => $this->telemetry_available ?? true,
            'today_recipients' => $todayRecipients,
            'today_hard_bounces' => $this->today_hard_bounces ?? null,
            'today_soft_bounces' => $this->today_soft_bounces ?? null,
            'bounce_rate' => $this->bounce_rate ?? null,
            'abuse_status' => $this->abuse_status ?? 'healthy',
            'active_alerts' => $this->active_alerts ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
