<?php

namespace App\Services\Abuse;

use App\Models\AbuseIncident;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AbuseIncidentService
{
    public function __construct(
        protected ?AuditService $auditService = null
    ) {
        $this->auditService = $auditService ?? app(AuditService::class);
    }

    /**
     * Record a new abuse incident in the persistent relational ledger.
     * Guarantees fail-safe execution, deduplication via idempotency key, and evidence sanitization.
     *
     * @param array $data
     * @return AbuseIncident|null
     */
    public function record(array $data): ?AbuseIncident
    {
        try {
            // 1. Idempotency Check: Return existing record if deduplication key already observed
            $idempotencyKey = $data['idempotency_key'] ?? null;
            if (!empty($idempotencyKey)) {
                $existing = AbuseIncident::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            // 2. Resolve Entity Identifiers & Historical Snapshots
            $tenantId = $data['tenant_id'] ?? null;
            $tenantEmail = $data['tenant_email'] ?? null;
            if (!empty($tenantId) && empty($tenantEmail)) {
                $tenantEmail = User::where('id', $tenantId)->value('email');
            }

            $mailboxId = $data['mailbox_id'] ?? null;
            $mailboxEmail = $data['mailbox_email'] ?? null;
            $domainId = $data['domain_id'] ?? null;
            $domainName = $data['domain_name'] ?? null;

            if (!empty($mailboxId)) {
                $mailbox = Mailbox::with('domain')->find($mailboxId);
                if ($mailbox) {
                    if (empty($mailboxEmail)) {
                        $mailboxEmail = $mailbox->email;
                    }
                    if (empty($domainId)) {
                        $domainId = $mailbox->domain_id;
                    }
                    if (empty($domainName) && $mailbox->domain) {
                        $domainName = $mailbox->domain->domain_name;
                    }
                    if (empty($tenantId) && $mailbox->domain?->user_id) {
                        $tenantId = $mailbox->domain->user_id;
                        $tenantEmail = $mailbox->domain->user?->email;
                    }
                }
            } elseif (!empty($domainId) && empty($domainName)) {
                $domain = Domain::with('user')->find($domainId);
                if ($domain) {
                    $domainName = $domain->domain_name;
                    if (empty($tenantId)) {
                        $tenantId = $domain->user_id;
                        $tenantEmail = $domain->user?->email;
                    }
                }
            }

            // 3. Recursive Evidence Sanitization (Zero credentials, hashes, or payload secrets)
            $rawEvidence = $data['evidence'] ?? [];
            $sanitizedEvidence = $this->sanitizeEvidence($rawEvidence);

            // 4. Create Persistent Incident Record
            $incident = AbuseIncident::create([
                'uuid'             => (string) Str::uuid(),
                'tenant_id'        => $tenantId,
                'tenant_email'     => $tenantEmail,
                'domain_id'        => $domainId,
                'domain_name'      => $domainName,
                'mailbox_id'       => $mailboxId,
                'mailbox_email'    => $mailboxEmail,
                'incident_type'    => $data['incident_type'],
                'severity'         => $data['severity'] ?? 'warning',
                'status'           => $data['status'] ?? 'open',
                'detection_source' => $data['detection_source'] ?? 'log_parser',
                'summary'          => Str::limit((string) ($data['summary'] ?? 'Abuse incident detected'), 500, ''),
                'threshold_value'  => isset($data['threshold_value']) ? (string) $data['threshold_value'] : null,
                'observed_value'   => isset($data['observed_value']) ? (string) $data['observed_value'] : null,
                'evidence'         => $sanitizedEvidence,
                'idempotency_key'  => $idempotencyKey,
                'occurred_at'      => isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : Carbon::now('UTC'),
            ]);

            if ($incident && $tenantId) {
                try {
                    $category = match ($incident->incident_type) {
                        'smtp_quota_exceeded', 'quota_exhaustion' => 'smtp_quota',
                        'dns_drift' => 'domain_dns',
                        default => 'security_abuse',
                    };
                    $actionUrl = $category === 'smtp_quota' ? '/smtp' : ($category === 'domain_dns' && $domainId ? "/domains/{$domainId}/setup" : '/dashboard');

                    app(\App\Services\TenantNotificationService::class)->notify(
                        tenantId: (int) $tenantId,
                        category: $category,
                        severity: $incident->severity === 'critical' ? 'critical' : 'warning',
                        title: 'Security Alert: ' . ucfirst(str_replace('_', ' ', $incident->incident_type)),
                        message: $incident->summary,
                        actionUrl: $actionUrl,
                        deduplicationKey: "abuse_incident:{$incident->uuid}",
                        data: [
                            'incident_id'   => $incident->id,
                            'incident_type' => $incident->incident_type,
                            'domain_name'   => $incident->domain_name,
                            'mailbox_email' => $incident->mailbox_email,
                        ]
                    );
                } catch (Throwable $e) {}
            }

            return $incident;

        } catch (Throwable $e) {
            // Fail-safe: Log error without halting SMTP flow or log processing
            Log::channel('abuse')->error('AbuseIncidentService: Failed to record abuse incident in ledger', [
                'incident_type' => $data['incident_type'] ?? 'unknown',
                'tenant_id'     => $data['tenant_id'] ?? null,
                'error_class'   => get_class($e),
                'error_message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resolve an open abuse incident with administrative attribution and audit logging.
     *
     * @param AbuseIncident $incident
     * @param User $actor
     * @param string|null $notes
     * @return AbuseIncident
     */
    public function resolve(AbuseIncident $incident, User $actor, ?string $notes = null): AbuseIncident
    {
        $beforeStatus = $incident->status;

        $incident->update([
            'status'           => 'resolved',
            'resolved_at'      => Carbon::now('UTC'),
            'resolved_by'      => $actor->id,
            'resolution_notes' => $notes ? Str::limit($notes, 1000, '') : 'Resolved by administrator',
        ]);

        $this->auditService->record(
            action: 'admin.abuse_incident.resolve',
            entityType: 'AbuseIncident',
            entityId: $incident->id,
            before: ['status' => $beforeStatus],
            after: [
                'incident_uuid'    => $incident->uuid,
                'status'           => 'resolved',
                'resolved_at'      => $incident->resolved_at?->toIso8601String(),
                'resolved_by'      => $actor->id,
                'resolution_notes' => $incident->resolution_notes,
                'outcome'          => 'success',
            ],
            actor: $actor,
            reason: $notes ?? 'Administrative abuse incident resolution'
        );

        if ($incident->tenant_id) {
            try {
                app(\App\Services\TenantNotificationService::class)->notify(
                    tenantId: (int) $incident->tenant_id,
                    category: 'security_abuse',
                    severity: 'success',
                    title: 'Security Incident Resolved: ' . ucfirst(str_replace('_', ' ', $incident->incident_type)),
                    message: 'Your reported security incident has been reviewed and resolved.',
                    actionUrl: '/dashboard',
                    deduplicationKey: "abuse_resolved:{$incident->uuid}",
                    data: ['incident_id' => $incident->id]
                );
            } catch (\Throwable $e) {}
        }

        return $incident->refresh();
    }

    /**
     * Dismiss an abuse incident (false positive or accepted risk) with audit logging.
     *
     * @param AbuseIncident $incident
     * @param User $actor
     * @param string|null $notes
     * @return AbuseIncident
     */
    public function dismiss(AbuseIncident $incident, User $actor, ?string $notes = null): AbuseIncident
    {
        $beforeStatus = $incident->status;

        $incident->update([
            'status'           => 'dismissed',
            'resolved_at'      => Carbon::now('UTC'),
            'resolved_by'      => $actor->id,
            'resolution_notes' => $notes ? Str::limit($notes, 1000, '') : 'Dismissed by administrator',
        ]);

        $this->auditService->record(
            action: 'admin.abuse_incident.dismiss',
            entityType: 'AbuseIncident',
            entityId: $incident->id,
            before: ['status' => $beforeStatus],
            after: [
                'incident_uuid'    => $incident->uuid,
                'status'           => 'dismissed',
                'resolved_at'      => $incident->resolved_at?->toIso8601String(),
                'resolved_by'      => $actor->id,
                'resolution_notes' => $incident->resolution_notes,
                'outcome'          => 'success',
            ],
            actor: $actor,
            reason: $notes ?? 'Administrative abuse incident dismissal'
        );

        return $incident->refresh();
    }

    /**
     * Sanitize structured incident evidence before database insertion.
     */
    public function sanitizeEvidence(mixed $evidence): mixed
    {
        if (empty($evidence)) {
            return [];
        }

        return $this->auditService->sanitizeState($evidence);
    }
}
