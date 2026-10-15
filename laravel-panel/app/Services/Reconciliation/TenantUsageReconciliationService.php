<?php

namespace App\Services\Reconciliation;

use App\Models\Invoice;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class TenantUsageReconciliationService
{
    /**
     * Checks whether Redis connection is available.
     */
    public function isRedisAvailable(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Perform read-only reconciliation across tenant usage, SMTP quotas,
     * subscription states, and billing invoices.
     *
     * @param int|null $tenantId Reconcile specific tenant ID or all if null
     * @param string|null $date UTC date in Y-m-d format (defaults to today)
     * @return array
     */
    public function reconcile(?int $tenantId = null, ?string $date = null): array
    {
        $targetDate = $date ?? Carbon::now('UTC')->format('Y-m-d');
        $redisAvailable = $this->isRedisAvailable();

        $query = User::query()->with([
            'plan',
            'domains' => fn($q) => $q->with(['mailboxes']),
            'invoices' => fn($q) => $q->latest(),
        ]);

        if ($tenantId !== null) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get();

        $discrepancies = [];
        $tenantReports = [];
        $overQuotaCount = 0;
        $subscriptionIssuesCount = 0;
        $billingIssuesCount = 0;
        $cleanCount = 0;

        foreach ($tenants as $tenant) {
            $tenantIssues = [];

            // ─── 1. Outbound Quota & Usage Reconciliation ───────────────────
            $planLimit = $tenant->plan?->daily_outbound_recipients ?? -1;
            $mailboxPlanLimit = $tenant->plan?->mailbox_daily_outbound_recipients ?? -1;

            $redisTenantUsage = null;
            $redisMailboxSum = 0;
            $mailboxTelemetry = [];

            if ($redisAvailable) {
                try {
                    $tenantKey = "outbound:tenant:{$tenant->id}:recipients:daily:{$targetDate}";
                    $val = Redis::get($tenantKey);
                    $redisTenantUsage = $val !== null && is_numeric($val) ? (int) $val : 0;

                    // Mailbox level inspection
                    foreach ($tenant->domains as $domain) {
                        foreach ($domain->mailboxes as $mailbox) {
                            $mbKey = "outbound:mailbox:{$mailbox->id}:recipients:daily:{$targetDate}";
                            $mbVal = Redis::get($mbKey);
                            $mbUsage = $mbVal !== null && is_numeric($mbVal) ? (int) $mbVal : 0;
                            $redisMailboxSum += $mbUsage;

                            $mailboxTelemetry[] = [
                                'mailbox_id' => $mailbox->id,
                                'email' => $mailbox->email,
                                'is_active' => (bool) $mailbox->is_active,
                                'usage_today' => $mbUsage,
                                'limit' => $mailboxPlanLimit,
                                'is_over_limit' => $mailboxPlanLimit > 0 && $mbUsage > $mailboxPlanLimit,
                            ];

                            if ($mailboxPlanLimit > 0 && $mbUsage > $mailboxPlanLimit) {
                                $tenantIssues[] = [
                                    'code' => 'MAILBOX_OVER_QUOTA',
                                    'severity' => 'warning',
                                    'message' => "Mailbox {$mailbox->email} exceeded daily limit ({$mbUsage} / {$mailboxPlanLimit})",
                                    'details' => ['mailbox_id' => $mailbox->id, 'usage' => $mbUsage, 'limit' => $mailboxPlanLimit],
                                ];
                            }
                        }
                    }
                } catch (Throwable $e) {
                    Log::channel('admin_smtp')->warning("Reconciliation Redis read failed for tenant {$tenant->id}: " . $e->getMessage());
                }
            }

            // MariaDB ledger inspection
            $ledgerRecord = TenantOutboundUsage::where('user_id', $tenant->id)
                ->where('usage_date', $targetDate)
                ->first();
            $ledgerUsage = $ledgerRecord ? (int) $ledgerRecord->recipient_count : 0;

            // Usage comparison
            $effectiveUsage = max($redisTenantUsage ?? 0, $ledgerUsage);

            // Discrepancy A: Ledger desynchronization (Redis has un-synced counts)
            if ($redisAvailable && $redisTenantUsage !== null && $redisTenantUsage > $ledgerUsage) {
                $tenantIssues[] = [
                    'code' => 'USAGE_LEDGER_DESYNC',
                    'severity' => 'info',
                    'message' => "Redis recipient counter ({$redisTenantUsage}) is ahead of MariaDB ledger ({$ledgerUsage}). Sync pending.",
                    'details' => ['redis_count' => $redisTenantUsage, 'ledger_count' => $ledgerUsage],
                ];
            }

            // Discrepancy B: Mailbox sum exceeds tenant recorded total
            if ($redisAvailable && $redisMailboxSum > ($redisTenantUsage ?? 0) && ($redisTenantUsage ?? 0) > 0) {
                $tenantIssues[] = [
                    'code' => 'MAILBOX_SUM_EXCEEDS_TENANT',
                    'severity' => 'warning',
                    'message' => "Sum of mailbox recipients ({$redisMailboxSum}) exceeds tenant counter ({$redisTenantUsage})",
                    'details' => ['mailbox_sum' => $redisMailboxSum, 'tenant_counter' => $redisTenantUsage],
                ];
            }

            // Discrepancy C: Over daily tenant quota
            $isOverQuota = false;
            $remainingDailyQuota = null;
            $quotaUsagePercent = null;

            if ($planLimit > 0) {
                $isOverQuota = $effectiveUsage > $planLimit;
                $remainingDailyQuota = max(0, $planLimit - $effectiveUsage);
                $quotaUsagePercent = round(min(100, ($effectiveUsage / $planLimit) * 100), 1);

                if ($isOverQuota) {
                    $overQuotaCount++;
                    $tenantIssues[] = [
                        'code' => 'TENANT_OVER_QUOTA',
                        'severity' => 'warning',
                        'message' => "Tenant exceeded daily outbound quota ({$effectiveUsage} / {$planLimit})",
                        'details' => ['effective_usage' => $effectiveUsage, 'daily_limit' => $planLimit],
                    ];
                }
            } elseif ($planLimit === -1) {
                $remainingDailyQuota = -1; // Unlimited
                $quotaUsagePercent = null;
            }

            // ─── 2. Subscription & Domain Invariant Checks ─────────────────
            $subscriptionState = 'valid';
            if ($tenant->is_admin) {
                $subscriptionState = 'admin_exempt';
            } elseif ($tenant->status === 'suspended') {
                $subscriptionState = 'suspended';
            } elseif ($tenant->status === 'pending') {
                $subscriptionState = 'pending';
            } elseif ($tenant->plan_id === null) {
                $subscriptionState = 'no_plan';
            } elseif ($tenant->plan_expires_at !== null && $tenant->plan_expires_at->isPast()) {
                $subscriptionState = 'expired';
            }

            // Check A: Active tenant with expired plan
            if ($tenant->status === 'active' && !$tenant->is_admin && $tenant->plan_expires_at !== null && $tenant->plan_expires_at->isPast()) {
                $subscriptionIssuesCount++;
                $tenantIssues[] = [
                    'code' => 'ACTIVE_EXPIRED_SUBSCRIPTION',
                    'severity' => 'critical',
                    'message' => "Tenant status is active but subscription expired on {$tenant->plan_expires_at->toIso8601String()}",
                    'details' => ['expires_at' => $tenant->plan_expires_at->toIso8601String()],
                ];
            }

            // Check B: Active tenant with no assigned plan
            if ($tenant->status === 'active' && !$tenant->is_admin && $tenant->plan_id === null) {
                $subscriptionIssuesCount++;
                $tenantIssues[] = [
                    'code' => 'ACTIVE_WITHOUT_PLAN',
                    'severity' => 'critical',
                    'message' => 'Tenant is marked active but has no assigned subscription plan',
                    'details' => [],
                ];
            }

            // Check C: Suspended tenant with active domains
            $activeDomainsCount = $tenant->domains->where('status', 'active')->count();
            if ($tenant->status === 'suspended' && $activeDomainsCount > 0) {
                $subscriptionIssuesCount++;
                $tenantIssues[] = [
                    'code' => 'SUSPENDED_TENANT_ACTIVE_DOMAINS',
                    'severity' => 'critical',
                    'message' => "Suspended tenant has {$activeDomainsCount} domain(s) still marked as active",
                    'details' => ['active_domains_count' => $activeDomainsCount],
                ];
            }

            // ─── 3. Invoices & Billing Reconciliation ───────────────────────
            $invoices = $tenant->invoices;
            $paidInvoices = $invoices->where('status', 'paid');
            $pendingInvoices = $invoices->where('status', 'pending');
            $failedInvoices = $invoices->where('status', 'failed');

            foreach ($paidInvoices as $paidInvoice) {
                if ($paidInvoice->paid_at === null) {
                    $billingIssuesCount++;
                    $tenantIssues[] = [
                        'code' => 'PAID_INVOICE_MISSING_PAID_AT',
                        'severity' => 'warning',
                        'message' => "Paid invoice {$paidInvoice->invoice_number} is missing paid_at timestamp",
                        'details' => ['invoice_id' => $paidInvoice->id, 'invoice_number' => $paidInvoice->invoice_number],
                    ];
                }

                if (strtoupper((string) $paidInvoice->currency) !== 'BDT') {
                    $billingIssuesCount++;
                    $tenantIssues[] = [
                        'code' => 'PAID_INVOICE_CURRENCY_MISMATCH',
                        'severity' => 'warning',
                        'message' => "Paid invoice {$paidInvoice->invoice_number} currency is '{$paidInvoice->currency}' instead of BDT",
                        'details' => ['invoice_id' => $paidInvoice->id, 'currency' => $paidInvoice->currency],
                    ];
                }
            }

            foreach ($pendingInvoices as $pendingInvoice) {
                if ($pendingInvoice->due_date && Carbon::parse($pendingInvoice->due_date)->isPast()) {
                    $tenantIssues[] = [
                        'code' => 'OVERDUE_PENDING_INVOICE',
                        'severity' => 'info',
                        'message' => "Pending invoice {$pendingInvoice->invoice_number} is past due date ({$pendingInvoice->due_date})",
                        'details' => ['invoice_id' => $pendingInvoice->id, 'due_date' => $pendingInvoice->due_date],
                    ];
                }
            }

            // Check D: Active customer tenant with no paid invoices while expiry is set
            if ($tenant->status === 'active' && !$tenant->is_admin && $paidInvoices->isEmpty() && $tenant->plan_expires_at !== null) {
                $tenantIssues[] = [
                    'code' => 'ACTIVE_TENANT_NO_PAID_INVOICE',
                    'severity' => 'warning',
                    'message' => 'Active tenant with active subscription date has no recorded paid invoices',
                    'details' => ['expires_at' => $tenant->plan_expires_at?->toIso8601String()],
                ];
            }

            // Summary aggregation for this tenant
            if (empty($tenantIssues)) {
                $cleanCount++;
            } else {
                foreach ($tenantIssues as $issue) {
                    $discrepancies[] = array_merge($issue, [
                        'tenant_id' => $tenant->id,
                        'tenant_email' => $tenant->email,
                        'tenant_name' => $tenant->name,
                    ]);
                }
            }

            $tenantReports[] = [
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'status' => $tenant->status,
                'subscription_state' => $subscriptionState,
                'plan_name' => $tenant->plan?->name ?? 'None',
                'daily_quota' => $planLimit,
                'effective_usage' => $effectiveUsage,
                'remaining_daily_quota' => $remainingDailyQuota,
                'quota_usage_percent' => $quotaUsagePercent,
                'is_over_quota' => $isOverQuota,
                'redis_usage' => $redisTenantUsage,
                'ledger_usage' => $ledgerUsage,
                'mailbox_count' => $tenant->domains->sum(fn($d) => $d->mailboxes->count()),
                'domain_count' => $tenant->domains->count(),
                'active_domains_count' => $activeDomainsCount,
                'invoices_count' => $invoices->count(),
                'paid_invoices_count' => $paidInvoices->count(),
                'pending_invoices_count' => $pendingInvoices->count(),
                'issues_count' => count($tenantIssues),
                'issues' => $tenantIssues,
            ];
        }

        return [
            'checked_at' => Carbon::now('UTC')->toIso8601String(),
            'reconciliation_date' => $targetDate,
            'telemetry_available' => $redisAvailable,
            'summary' => [
                'total_tenants_checked' => $tenants->count(),
                'clean_tenants_count' => $cleanCount,
                'tenants_with_discrepancies' => $tenants->count() - $cleanCount,
                'over_quota_tenants_count' => $overQuotaCount,
                'subscription_issues_count' => $subscriptionIssuesCount,
                'billing_issues_count' => $billingIssuesCount,
                'total_discrepancies_count' => count($discrepancies),
                'is_healthy' => count($discrepancies) === 0,
            ],
            'discrepancies' => $discrepancies,
            'tenants' => $tenantReports,
        ];
    }
}
