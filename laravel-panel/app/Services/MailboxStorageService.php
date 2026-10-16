<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MailboxStorageService
{
    /**
     * Optional fake measurement resolver for automated testing and simulation.
     *
     * @var Closure|null
     */
    protected static ?Closure $measurementResolver = null;

    /**
     * Cached environment support flag.
     *
     * @var bool|null
     */
    protected static ?bool $environmentSupported = null;

    /**
     * Fake a measurement resolver for testing.
     *
     * @param Closure|null $resolver function(Mailbox $mailbox): ?array
     */
    public static function fake(?Closure $resolver = null): void
    {
        self::$measurementResolver = $resolver;
    }

    /**
     * Restore original measurement behavior.
     */
    public static function restore(): void
    {
        self::$measurementResolver = null;
        self::$environmentSupported = null;
    }

    /**
     * Check if the runtime environment supports live Dovecot or mail filesystem measurement.
     */
    public function isMeasurementSupported(): bool
    {
        if (self::$measurementResolver !== null) {
            return true;
        }

        if (self::$environmentSupported !== null) {
            return self::$environmentSupported;
        }

        // On Windows or environments without doveadm / /var/vmail, measurement is unsupported
        if (PHP_OS_FAMILY === 'Windows') {
            return self::$environmentSupported = false;
        }

        // Check if doveadm binary is executable in PATH
        $doveadmExists = (bool) shell_exec('which doveadm 2>/dev/null');
        $vmailExists = is_dir(config('backup.vmail_source_path', '/var/vmail'));

        return self::$environmentSupported = ($doveadmExists || $vmailExists);
    }

    /**
     * Measure actual mailbox storage usage.
     * Returns array with 'bytes', 'source', 'measured_at' or null if unmeasurable.
     *
     * @param Mailbox $mailbox
     * @return array{bytes: int|null, source: string, measured_at: string|null, warning: string|null}
     */
    public function measureMailboxUsage(Mailbox $mailbox): array
    {
        // 1. Check for testing fake resolver
        if (self::$measurementResolver !== null) {
            $fakeResult = (self::$measurementResolver)($mailbox);
            if ($fakeResult !== null) {
                return [
                    'bytes'       => isset($fakeResult['bytes']) ? (int) $fakeResult['bytes'] : null,
                    'source'      => $fakeResult['source'] ?? 'mock',
                    'measured_at' => $fakeResult['measured_at'] ?? Carbon::now('UTC')->toIso8601String(),
                    'warning'     => $fakeResult['warning'] ?? null,
                ];
            }
        }

        // 2. Check environment support
        if (!$this->isMeasurementSupported()) {
            return [
                'bytes'       => null,
                'source'      => 'none',
                'measured_at' => null,
                'warning'     => 'Live mail storage telemetry is unmeasurable in this local environment (Linux Dovecot daemon or mail storage directory not accessible).',
            ];
        }

        // 3. Attempt doveadm quota query
        $email = escapeshellarg($mailbox->email);
        $doveadmCheck = shell_exec("doveadm -f tab quota get -u {$email} 2>/dev/null");
        if ($doveadmCheck && str_contains($doveadmCheck, 'STORAGE')) {
            $lines = explode("\n", trim($doveadmCheck));
            foreach ($lines as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (isset($cols[1]) && strtoupper($cols[1]) === 'STORAGE') {
                    $usedBytes = isset($cols[2]) ? (int) $cols[2] * 1024 : 0; // doveadm returns KiB
                    return [
                        'bytes'       => $usedBytes,
                        'source'      => 'doveadm',
                        'measured_at' => Carbon::now('UTC')->toIso8601String(),
                        'warning'     => null,
                    ];
                }
            }
        }

        // 4. Fallback: inspect Maildir directory size if directory exists
        $domainName = $mailbox->domain?->domain_name;
        if ($domainName && $mailbox->local_part) {
            $vmailBase = config('backup.vmail_source_path', '/var/vmail');
            $maildirPath = "{$vmailBase}/{$domainName}/{$mailbox->local_part}";

            if (is_dir($maildirPath)) {
                $sizeFile = "{$maildirPath}/maildirsize";
                if (file_exists($sizeFile) && is_readable($sizeFile)) {
                    $content = file_get_contents($sizeFile);
                    $lines = explode("\n", trim($content));
                    if (isset($lines[1])) {
                        $parts = preg_split('/\s+/', trim($lines[1]));
                        if (isset($parts[0]) && is_numeric($parts[0])) {
                            return [
                                'bytes'       => (int) $parts[0],
                                'source'      => 'maildirsize',
                                'measured_at' => Carbon::now('UTC')->toIso8601String(),
                                'warning'     => null,
                            ];
                        }
                    }
                }
            }
        }

        // 5. Default fallback when measurable environment exists but mailbox not yet provisioned on disk
        return [
            'bytes'       => null,
            'source'      => 'filesystem',
            'measured_at' => null,
            'warning'     => 'Mailbox maildir not yet provisioned or accessible on storage disk.',
        ];
    }

    /**
     * Compute comprehensive storage telemetry for a single mailbox.
     *
     * @param Mailbox $mailbox
     * @return array
     */
    public function getMailboxStorage(Mailbox $mailbox): array
    {
        $mailbox->loadMissing(['domain.user.plan']);

        $domain = $mailbox->domain;
        $user = $domain?->user;
        $plan = $user?->plan;

        $quotaMb = (int) $mailbox->quota_mb;
        $isUnlimited = ($quotaMb === -1);
        $quotaBytes = $isUnlimited ? -1 : ($quotaMb * 1048576);
        $planLimitMb = (int) ($plan?->storage_mb_per_mailbox ?? 0);

        // Measure usage
        $measurement = $this->measureMailboxUsage($mailbox);
        $usageBytes = $measurement['bytes'];
        $isMeasured = ($usageBytes !== null);

        $usageMb = $isMeasured ? round($usageBytes / 1048576, 2) : null;
        $remainingBytes = null;
        $remainingMb = null;
        $utilizationPercent = null;

        if ($isMeasured) {
            if ($isUnlimited) {
                $remainingBytes = null;
                $remainingMb = null;
                $utilizationPercent = 0.0;
            } elseif ($quotaBytes > 0) {
                $remainingBytes = max(0, $quotaBytes - $usageBytes);
                $remainingMb = round($remainingBytes / 1048576, 2);
                $utilizationPercent = round(($usageBytes / $quotaBytes) * 100, 1);
            } else {
                $remainingBytes = 0;
                $remainingMb = 0.0;
                $utilizationPercent = 100.0;
            }
        }

        $status = $this->calculateStatus($utilizationPercent, $isMeasured);

        return [
            'mailbox_id'          => $mailbox->id,
            'email'               => $mailbox->email,
            'local_part'          => $mailbox->local_part,
            'domain_id'           => $mailbox->domain_id,
            'domain_name'         => $domain?->domain_name,
            'domain_status'       => $domain?->status,
            'is_active'           => (bool) $mailbox->is_active,
            'quota_mb'            => $quotaMb,
            'quota_bytes'         => $quotaBytes,
            'quota_formatted'     => $this->formatQuota($quotaMb),
            'plan_limit_mb'       => $planLimitMb,
            'plan_limit_formatted'=> $this->formatQuota($planLimitMb),
            'usage_bytes'         => $usageBytes,
            'usage_mb'            => $usageMb,
            'usage_formatted'     => $isMeasured ? $this->formatBytes($usageBytes) : 'Unavailable',
            'remaining_bytes'     => $remainingBytes,
            'remaining_mb'        => $remainingMb,
            'remaining_formatted' => $remainingBytes !== null ? $this->formatBytes($remainingBytes) : ($isUnlimited ? 'Unlimited' : 'Unavailable'),
            'utilization_percent' => $utilizationPercent,
            'status'              => $status,
            'status_label'        => $this->statusLabel($status),
            'is_measured'         => $isMeasured,
            'measurement_source'  => $measurement['source'],
            'measured_at'         => $measurement['measured_at'],
            'warning'             => $measurement['warning'],
        ];
    }

    /**
     * Compute aggregate tenant storage overview and paginated mailbox breakdown.
     *
     * @param User $tenant
     * @param array $filters
     * @return array
     */
    public function getTenantStorageOverview(User $tenant, array $filters = []): array
    {
        $tenant->loadMissing('plan');

        $query = $tenant->mailboxes()->with('domain');

        if (!empty($filters['domain_id'])) {
            $query->where('mailboxes.domain_id', $filters['domain_id']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('mailboxes.local_part', 'like', $search)
                  ->orWhere('mailboxes.email', 'like', $search)
                  ->orWhere('mailboxes.display_name', 'like', $search);
            });
        }

        $allMailboxes = $query->get();

        $totalMailboxes = $allMailboxes->count();
        $activeMailboxes = $allMailboxes->where('is_active', true)->count();

        $totalQuotaMb = 0;
        $hasUnlimitedQuota = false;
        $measuredCount = 0;
        $unmeasuredCount = 0;
        $totalUsageBytes = 0;
        $anyMeasured = false;

        $statusCounts = [
            'normal'     => 0,
            'warning'    => 0,
            'near_limit' => 0,
            'over_limit' => 0,
            'unknown'    => 0,
        ];

        $storageList = [];

        foreach ($allMailboxes as $mb) {
            $storage = $this->getMailboxStorage($mb);
            $storageList[] = $storage;

            if ($storage['quota_mb'] === -1) {
                $hasUnlimitedQuota = true;
            } else {
                $totalQuotaMb += $storage['quota_mb'];
            }

            $statusCounts[$storage['status']]++;

            if ($storage['is_measured']) {
                $anyMeasured = true;
                $measuredCount++;
                $totalUsageBytes += $storage['usage_bytes'];
            } else {
                $unmeasuredCount++;
            }
        }

        // Filter by status if requested
        if (!empty($filters['status']) && in_array($filters['status'], ['normal', 'warning', 'near_limit', 'over_limit', 'unknown'], true)) {
            $storageList = array_values(array_filter($storageList, fn ($s) => $s['status'] === $filters['status']));
        }

        // Pagination
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 15)));
        $offset = ($page - 1) * $perPage;
        $pagedData = array_slice($storageList, $offset, $perPage);

        $paginator = new LengthAwarePaginator(
            $pagedData,
            count($storageList),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $planLimitPerMailboxMb = (int) ($tenant->plan?->storage_mb_per_mailbox ?? 0);
        $totalQuotaFormatted = $hasUnlimitedQuota ? 'Unlimited' : $this->formatQuota($totalQuotaMb);
        $totalUsageMb = $anyMeasured ? round($totalUsageBytes / 1048576, 2) : null;
        $totalUsageFormatted = $anyMeasured ? $this->formatBytes($totalUsageBytes) : 'Unavailable';

        $aggregateUtilizationPercent = null;
        if ($anyMeasured && !$hasUnlimitedQuota && $totalQuotaMb > 0) {
            $aggregateUtilizationPercent = round(($totalUsageBytes / ($totalQuotaMb * 1048576)) * 100, 1);
        }

        $aggregateStatus = 'unknown';
        if ($anyMeasured) {
            if ($statusCounts['over_limit'] > 0) {
                $aggregateStatus = 'over_limit';
            } elseif ($statusCounts['near_limit'] > 0) {
                $aggregateStatus = 'near_limit';
            } elseif ($statusCounts['warning'] > 0) {
                $aggregateStatus = 'warning';
            } else {
                $aggregateStatus = 'normal';
            }
        }

        return [
            'summary' => [
                'total_mailboxes'                    => $totalMailboxes,
                'active_mailboxes'                   => $activeMailboxes,
                'total_quota_mb'                     => $hasUnlimitedQuota ? -1 : $totalQuotaMb,
                'total_quota_formatted'              => $totalQuotaFormatted,
                'plan_storage_limit_per_mailbox_mb'  => $planLimitPerMailboxMb,
                'plan_storage_limit_formatted'       => $this->formatQuota($planLimitPerMailboxMb),
                'measured_mailboxes_count'           => $measuredCount,
                'unmeasured_mailboxes_count'         => $unmeasuredCount,
                'total_usage_bytes'                  => $anyMeasured ? $totalUsageBytes : null,
                'total_usage_mb'                     => $totalUsageMb,
                'total_usage_formatted'              => $totalUsageFormatted,
                'aggregate_utilization_percent'      => $aggregateUtilizationPercent,
                'aggregate_status'                   => $aggregateStatus,
                'aggregate_status_label'             => $this->statusLabel($aggregateStatus),
                'status_counts'                      => $statusCounts,
                'measurement_source'                 => $anyMeasured ? ($storageList[0]['measurement_source'] ?? 'mixed') : 'none',
                'environment_support'                => $this->isMeasurementSupported(),
                'environment_note'                   => $this->isMeasurementSupported()
                    ? 'Mail storage telemetry is actively measured via system mail storage.'
                    : 'Mail storage telemetry is unmeasurable in this local development environment (Linux Dovecot daemon or /var/vmail not accessible).',
            ],
            'data'    => $paginator->items(),
            'links'   => [
                'first' => $paginator->url(1),
                'last'  => $paginator->url($paginator->lastPage()),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
            ],
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'from'         => $paginator->firstItem(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'to'           => $paginator->lastItem(),
                'total'        => $paginator->total(),
            ],
        ];
    }

    /**
     * Determine storage status category based on utilization percentage.
     *
     * @param float|null $utilizationPercent
     * @param bool $isMeasured
     * @return string
     */
    public function calculateStatus(?float $utilizationPercent, bool $isMeasured): string
    {
        if (!$isMeasured || $utilizationPercent === null) {
            return 'unknown';
        }

        if ($utilizationPercent >= 100.0) {
            return 'over_limit';
        }

        if ($utilizationPercent >= 90.0) {
            return 'near_limit';
        }

        if ($utilizationPercent >= 80.0) {
            return 'warning';
        }

        return 'normal';
    }

    /**
     * Human-readable label for a status string.
     */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            'normal'     => 'Normal',
            'warning'    => 'Warning (80%+)',
            'near_limit' => 'Near Limit (90%+)',
            'over_limit' => 'Over Limit (100%+)',
            default      => 'Unknown',
        };
    }

    /**
     * Format byte values to human readable unit.
     */
    public function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Unavailable';
        }

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * Format MB values to human readable unit.
     */
    public function formatQuota(int $quotaMb): string
    {
        if ($quotaMb === -1) {
            return 'Unlimited';
        }

        if ($quotaMb >= 1024) {
            return round($quotaMb / 1024, 1) . ' GB';
        }

        return $quotaMb . ' MB';
    }
}
