<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantAbuseIncidentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'uuid'                  => $this->uuid,
            'incident_type'         => $this->incident_type,
            'severity'              => $this->severity,
            'status'                => $this->status,
            'summary'               => $this->summary,
            'domain_name'           => $this->domain_name ?? $this->domain?->domain_name,
            'mailbox_email'         => $this->mailbox_email ?? $this->mailbox?->email,
            'occurred_at'           => $this->occurred_at?->toIso8601String(),
            'resolved_at'           => $this->resolved_at?->toIso8601String(),
            'suggested_remediation' => $this->getSuggestedRemediation($this->incident_type ?? ''),
            'created_at'            => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Provide customer-facing actionable remediation advice for specific incident types.
     */
    protected function getSuggestedRemediation(string $type): string
    {
        return match ($type) {
            'consecutive_hard_bounces' => 'Review and verify recipient email addresses in your recent campaigns. Remove invalid or inactive mailboxes before attempting further delivery.',
            'daily_hard_bounce_threshold' => 'Daily bounce threshold exceeded. Pause bulk mailings and scrub your recipient list using an email address verification tool.',
            'high_hard_bounce_rate' => 'Overall bounce rate exceeds 10%. Ensure you are sending only to opted-in recipients who expect your messages.',
            'quota_exhaustion', 'quota_breach' => 'Daily outbound recipient limit reached. Wait until 00:00 UTC for reset or upgrade your subscription plan.',
            'dns_drift' => 'Authoritative DNS records differ from expected settings. Review and restore MX, SPF, or DKIM records in your domain registrar.',
            default => 'Review your outbound mailing activity and ensure compliance with deliverability and acceptable use policies.',
        };
    }
}
