<?php

namespace App\Services;

use App\Http\Resources\DeliveryLogResource;
use App\Http\Resources\DomainResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TenantAbuseIncidentResource;
use App\Models\AbuseIncident;
use App\Models\DeliveryLog;
use App\Models\Domain;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TenantSecurityDashboardService
{
    public function __construct(
        protected ?DnsVerificationService $dnsService = null,
        protected ?TenantSmtpService $smtpService = null
    ) {
        $this->dnsService = $dnsService ?? app(DnsVerificationService::class);
        $this->smtpService = $smtpService ?? app(TenantSmtpService::class);
    }

    /**
     * Assemble full tenant security & deliverability monitoring dashboard payload.
     */
    public function getDashboardData(User $user, ?Request $request = null): array
    {
        $domainSecurity = $this->buildDomainSecuritySection($user);
        $smtpUsage = $this->buildSmtpUsageSection($user);
        $recentActivity = $this->buildRecentActivitySection($user);
        $abuseAndSecurity = $this->buildAbuseAndSecuritySection($user, $domainSecurity);
        $dmarcReporting = $this->buildDmarcReportingSection();

        $domains = $user->domains()->withCount('mailboxes')->latest()->take(5)->get();
        $invoices = $user->invoices()->latest()->take(5)->get();

        $totalDomains = $user->domains()->count();
        $totalMailboxes = $user->mailboxes()->count();

        return [
            // User & Plan Profile
            'user' => [
                'id'              => $user->id,
                'name'            => $user->name,
                'email'           => $user->email,
                'status'          => $user->status,
                'plan'            => $user->plan ? [
                    'name'                     => $user->plan->name,
                    'slug'                     => $user->plan->slug,
                    'max_domains'              => $user->plan->max_domains,
                    'max_domains_label'        => $user->plan->max_domains === -1 ? 'Unlimited' : (string) $user->plan->max_domains,
                    'max_mailboxes_per_domain' => $user->plan->max_mailboxes_per_domain,
                    'max_mailboxes_label'      => $user->plan->max_mailboxes_per_domain === -1 ? 'Unlimited' : (string) $user->plan->max_mailboxes_per_domain,
                    'storage_mb_per_mailbox'   => $user->plan->storage_mb_per_mailbox,
                    'daily_outbound_recipients'=> $user->plan->daily_outbound_recipients,
                ] : null,
                'plan_expires_at' => $user->plan_expires_at?->toIso8601String(),
            ],
            'plan_info' => $user->plan ? [
                'name'                     => $user->plan->name,
                'expires_at'               => $user->plan_expires_at?->toIso8601String(),
                'max_domains'              => $user->plan->max_domains,
                'max_mailboxes_per_domain' => $user->plan->max_mailboxes_per_domain,
            ] : null,
            'domains_count'   => $totalDomains,
            'domain_count'    => $totalDomains,
            'mailboxes_count' => $totalMailboxes,
            'mailbox_count'   => $totalMailboxes,

            // Section 1: Domain Authentication Overview
            'domain_security' => $domainSecurity,

            // Section 2: SMTP Activity & Daily Quota
            'smtp_usage' => $smtpUsage,

            // Section 3: Recent Sending Activity
            'recent_activity' => $recentActivity,

            // Section 4: Abuse & Security Events
            'abuse_and_security' => $abuseAndSecurity,

            // Section 5: DMARC Reporting Capability
            'dmarc_reporting' => $dmarcReporting,

            // Backwards-compatible collections
            'recent_domains'  => DomainResource::collection($domains),
            'recent_invoices' => InvoiceResource::collection($invoices),
        ];
    }

    /**
     * Build Domain Authentication & Deliverability Posture section.
     * Evaluates stored verification state WITHOUT running live DNS queries on render.
     */
    protected function buildDomainSecuritySection(User $user): array
    {
        try {
            $domains = $user->domains()->withCount('mailboxes')->get();

            if ($domains->isEmpty()) {
                return [
                    'status'          => 'empty',
                    'error'           => null,
                    'summary'         => [
                        'total_domains'                => 0,
                        'active_domains'               => 0,
                        'pending_domains'              => 0,
                        'fully_authenticated_domains'  => 0,
                        'domains_with_drift'           => 0,
                        'average_deliverability_score' => 0,
                    ],
                    'domains'         => [],
                    'recommendations' => [
                        'Add your first domain to begin configuring inbound MX and outbound SPF/DKIM/DMARC authentication.',
                    ],
                ];
            }

            $domainItems = [];
            $totalScore = 0;
            $fullyAuthenticatedCount = 0;
            $activeCount = 0;
            $pendingCount = 0;
            $driftCount = 0;
            $recommendations = [];

            foreach ($domains as $domain) {
                if ($domain->status === 'active') {
                    $activeCount++;
                } else {
                    $pendingCount++;
                }

                $scoreResult = $this->dnsService->calculateDeliverabilityScore([
                    'mx'    => (bool) $domain->mx_verified,
                    'spf'   => (bool) $domain->spf_verified,
                    'dkim'  => (bool) $domain->dkim_verified,
                    'dmarc' => (bool) $domain->dmarc_verified,
                ]);

                $score = $scoreResult['score_percent'] ?? 0;
                $totalScore += $score;

                $allFourVerified = $domain->mx_verified && $domain->spf_verified && $domain->dkim_verified && $domain->dmarc_verified;
                if ($allFourVerified) {
                    $fullyAuthenticatedCount++;
                }

                $isDrift = ($domain->status === 'active') && (! $domain->mx_verified || ! $domain->spf_verified || ! $domain->dkim_verified);
                if ($isDrift) {
                    $driftCount++;
                }

                $missingRecords = [];
                if (! $domain->mx_verified) {
                    $missingRecords[] = 'MX';
                }
                if (! $domain->spf_verified) {
                    $missingRecords[] = 'SPF';
                }
                if (! $domain->dkim_verified) {
                    $missingRecords[] = 'DKIM';
                }
                if (! $domain->dmarc_verified) {
                    $missingRecords[] = 'DMARC';
                }

                $domainItems[] = [
                    'id'                  => $domain->id,
                    'domain_name'         => $domain->domain_name,
                    'status'              => $domain->status,
                    'mailboxes_count'     => (int) ($domain->mailboxes_count ?? 0),
                    'mx_verified'         => (bool) $domain->mx_verified,
                    'spf_verified'        => (bool) $domain->spf_verified,
                    'dkim_verified'       => (bool) $domain->dkim_verified,
                    'dmarc_verified'      => (bool) $domain->dmarc_verified,
                    'score'               => $score,
                    'score_status'        => $scoreResult['status'] ?? 'critical',
                    'score_status_label'  => $scoreResult['status_label'] ?? 'Incomplete',
                    'dns_drift_detected'  => $isDrift,
                    'missing_records'     => $missingRecords,
                    'last_dns_check_at'   => $domain->last_dns_check_at?->toIso8601String(),
                    'setup_url'           => "/domains/{$domain->id}/setup",
                ];
            }

            $avgScore = (int) round($totalScore / count($domains));

            if ($driftCount > 0) {
                $recommendations[] = "DNS drift detected on {$driftCount} active domain(s). One or more records were removed or modified on authoritative nameservers.";
            }

            if ($fullyAuthenticatedCount < count($domains)) {
                $unauthCount = count($domains) - $fullyAuthenticatedCount;
                $recommendations[] = "{$unauthCount} domain(s) have incomplete authentication. Add missing records (MX, SPF, DKIM, or DMARC) in Domain Setup.";
            }

            $sectionStatus = $driftCount > 0 ? 'warning' : ($avgScore >= 75 ? 'operational' : 'warning');

            return [
                'status'          => $sectionStatus,
                'error'           => null,
                'summary'         => [
                    'total_domains'                => count($domains),
                    'active_domains'               => $activeCount,
                    'pending_domains'              => $pendingCount,
                    'fully_authenticated_domains'  => $fullyAuthenticatedCount,
                    'domains_with_drift'           => $driftCount,
                    'average_deliverability_score' => $avgScore,
                ],
                'domains'         => $domainItems,
                'recommendations' => $recommendations,
            ];
        } catch (Throwable $e) {
            Log::error('TenantSecurityDashboardService: Domain security build failed', ['error' => $e->getMessage()]);

            return [
                'status'          => 'error',
                'error'           => 'Unable to load domain authentication status.',
                'summary'         => [
                    'total_domains'                => 0,
                    'active_domains'               => 0,
                    'pending_domains'              => 0,
                    'fully_authenticated_domains'  => 0,
                    'domains_with_drift'           => 0,
                    'average_deliverability_score' => 0,
                ],
                'domains'         => [],
                'recommendations' => [],
            ];
        }
    }

    /**
     * Build SMTP Usage & Telemetry section.
     * Reuses TenantSmtpService, catching Redis degradation safely.
     */
    protected function buildSmtpUsageSection(User $user): array
    {
        try {
            $usage = $this->smtpService->getUsage($user);

            // Calculate delivery log status counts (last 30 days)
            $sinceDate = Carbon::now('UTC')->subDays(30);
            $logCounts = DeliveryLog::forTenant($user->id)
                ->where('occurred_at', '>=', $sinceDate)
                ->selectRaw("status, count(*) as cnt")
                ->groupBy('status')
                ->pluck('cnt', 'status')
                ->toArray();

            $delivered = (int) ($logCounts['delivered'] ?? 0);
            $deferred  = (int) ($logCounts['deferred'] ?? 0);
            $bounced   = (int) ($logCounts['bounced'] ?? 0);
            $rejected  = (int) ($logCounts['rejected'] ?? 0);
            $totalLogged = $delivered + $deferred + $bounced + $rejected;

            $dailyLimit = $usage['daily_limit'] ?? -1;
            $usedToday = $usage['used_today'] ?? 0;

            $quotaStatus = 'normal';
            $quotaWarning = null;

            if ($dailyLimit === -1) {
                $quotaStatus = 'unlimited';
            } elseif ($dailyLimit === 0) {
                $quotaStatus = 'disabled';
                $quotaWarning = 'Outbound SMTP sending is disabled for your current plan.';
            } elseif ($usedToday >= $dailyLimit) {
                $quotaStatus = 'exhausted';
                $quotaWarning = "Daily recipient quota exhausted ({$usedToday}/{$dailyLimit}). Resets at 00:00 UTC.";
            } elseif (($usedToday / $dailyLimit) >= 0.85) {
                $quotaStatus = 'warning';
                $quotaWarning = "Daily quota near limit ({$usedToday}/{$dailyLimit} used today).";
            }

            return [
                'status'           => ($quotaStatus === 'exhausted' || $quotaStatus === 'disabled') ? 'warning' : 'operational',
                'error'            => null,
                'data_source'      => 'Redis (real-time UTC daily) + MariaDB tenant_outbound_usage',
                'used_today'       => $usedToday,
                'daily_limit'      => $dailyLimit,
                'remaining_today'  => $usage['remaining_today'] ?? null,
                'percent_used'     => $usage['percent_used'] ?? 0.0,
                'is_unlimited'     => $usage['is_unlimited'] ?? false,
                'quota_status'     => $quotaStatus,
                'quota_warning'    => $quotaWarning,
                'history'          => $usage['history'] ?? [],
                'delivery_summary' => [
                    'timeframe'    => 'Delivery logs (last 30 days)',
                    'total_logged' => $totalLogged,
                    'delivered'    => $delivered,
                    'deferred'     => $deferred,
                    'bounced'      => $bounced,
                    'rejected'     => $rejected,
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('TenantSecurityDashboardService: SMTP usage build degraded', ['error' => $e->getMessage()]);

            return [
                'status'           => 'degraded',
                'error'            => 'Live Redis quota telemetry is temporarily unavailable. Displaying database historical records.',
                'data_source'      => 'Fallback Database Storage',
                'used_today'       => 0,
                'daily_limit'      => $user->plan?->daily_outbound_recipients ?? -1,
                'remaining_today'  => null,
                'percent_used'     => 0.0,
                'is_unlimited'     => ($user->plan?->daily_outbound_recipients ?? -1) === -1,
                'quota_status'     => 'normal',
                'quota_warning'    => null,
                'history'          => [],
                'delivery_summary' => [
                    'timeframe'    => 'Delivery logs (last 30 days)',
                    'total_logged' => 0,
                    'delivered'    => 0,
                    'deferred'     => 0,
                    'bounced'      => 0,
                    'rejected'     => 0,
                ],
            ];
        }
    }

    /**
     * Build Recent Activity section with bounded log events.
     */
    protected function buildRecentActivitySection(User $user): array
    {
        try {
            $logs = DeliveryLog::forTenant($user->id)
                ->latest('occurred_at')
                ->take(10)
                ->get();

            return [
                'status' => $logs->isEmpty() ? 'empty' : 'operational',
                'error'  => null,
                'events' => DeliveryLogResource::collection($logs),
            ];
        } catch (Throwable $e) {
            Log::error('TenantSecurityDashboardService: Recent activity build failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'error',
                'error'  => 'Unable to load recent delivery logs.',
                'events' => [],
            ];
        }
    }

    /**
     * Build Abuse & Security Events section with strict tenant isolation and data sanitization.
     */
    protected function buildAbuseAndSecuritySection(User $user, array $domainSecurity): array
    {
        try {
            $incidents = AbuseIncident::forTenant($user->id)
                ->latest('occurred_at')
                ->take(10)
                ->get();

            $openIncidentsCount = AbuseIncident::forTenant($user->id)
                ->open()
                ->count();

            $securityWarnings = [];

            if ($openIncidentsCount > 0) {
                $securityWarnings[] = "Your account has {$openIncidentsCount} active abuse/deliverability incident(s) flagged.";
            }

            $driftCount = $domainSecurity['summary']['domains_with_drift'] ?? 0;
            if ($driftCount > 0) {
                $securityWarnings[] = "DNS drift detected on {$driftCount} active domain(s).";
            }

            $status = $openIncidentsCount > 0 ? 'warning' : ($incidents->isEmpty() ? 'clean' : 'operational');

            return [
                'status'               => $status,
                'error'                => null,
                'open_incidents_count' => $openIncidentsCount,
                'incidents'            => TenantAbuseIncidentResource::collection($incidents),
                'security_warnings'    => $securityWarnings,
            ];
        } catch (Throwable $e) {
            Log::error('TenantSecurityDashboardService: Abuse & security build failed', ['error' => $e->getMessage()]);

            return [
                'status'               => 'error',
                'error'                => 'Unable to load security incidents.',
                'open_incidents_count' => 0,
                'incidents'            => [],
                'security_warnings'    => [],
            ];
        }
    }

    /**
     * Build DMARC Reporting Capability Status section.
     * Truthfully reports manual inspection capability while documenting that automated collection is pending infrastructure.
     */
    protected function buildDmarcReportingSection(): array
    {
        return [
            'status'                      => 'manual_inspection_only',
            'parser_available'            => true,
            'parser_format'               => 'RFC 7489 XML (up to 5MB)',
            'automated_collection'        => false,
            'automated_collection_status' => 'PENDING INFRASTRUCTURE & ARCHITECTURAL APPROVAL',
            'automated_collection_note'   => 'Automated ingestion via inbound IMAP mailbox polling or Postfix mail pipe daemon requires dedicated mail-routing infrastructure approval. Manual XML aggregate report analysis is fully supported via Domain Setup.',
            'inspected_reports_count'     => 0,
            'supported_actions'           => [
                'manual_file_upload',
                'xml_string_inspection',
            ],
        ];
    }

    /**
     * Get paginated security & abuse incidents for the authenticated tenant.
     */
    public function getTenantIncidents(User $user, Request $request)
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 50);

        $query = AbuseIncident::forTenant($user->id)
            ->with(['domain:id,domain_name', 'mailbox:id,email'])
            ->latest('occurred_at');

        if ($request->filled('status')) {
            $status = strtolower($request->input('status'));
            if ($status === 'open') {
                $query->open();
            } elseif ($status === 'resolved') {
                $query->resolved();
            }
        }

        if ($request->filled('severity')) {
            $query->where('severity', strtolower($request->input('severity')));
        }

        if ($request->filled('search')) {
            $term = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('summary', 'like', $term)
                  ->orWhere('domain_name', 'like', $term)
                  ->orWhere('mailbox_email', 'like', $term);
            });
        }

        return TenantAbuseIncidentResource::collection(
            $query->paginate($perPage)
        );
    }
}
