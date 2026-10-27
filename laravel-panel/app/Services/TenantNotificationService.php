<?php

namespace App\Services;

use App\Models\TenantNotification;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Throwable;

class TenantNotificationService
{
    public const VALID_CATEGORIES = [
        'domain_dns',
        'security_abuse',
        'smtp_quota',
        'billing_subscription',
        'mailbox_rule',
        'system',
    ];

    public const IMMUTABLE_CATEGORIES = [
        'security_abuse',
        'billing_subscription',
    ];

    public const VALID_SEVERITIES = [
        'info',
        'warning',
        'critical',
        'success',
    ];

    /**
     * Dispatch an in-app tenant notification with atomic deduplication,
     * tenant preference enforcement, and sensitive credential scrubbing.
     *
     * @param int $tenantId
     * @param string $category
     * @param string $severity
     * @param string $title
     * @param string $message
     * @param string|null $actionUrl
     * @param string|null $deduplicationKey
     * @param array|null $data
     * @param bool $allowRecurrence
     * @return TenantNotification|null
     * @throws Throwable
     */
    public function notify(
        int $tenantId,
        string $category,
        string $severity,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $deduplicationKey = null,
        ?array $data = null,
        bool $allowRecurrence = false
    ): ?TenantNotification {
        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            $category = 'system';
        }

        if (!in_array($severity, self::VALID_SEVERITIES, true)) {
            $severity = 'info';
        }

        // Tenant Notification Preferences Enforcement:
        // Critical alerts ALWAYS bypass mute settings to ensure security & compliance.
        if ($severity !== 'critical') {
            $tenant = User::find($tenantId);
            if ($tenant && !$tenant->isNotificationCategoryEnabled($category)) {
                // Category muted by tenant preference
                return null;
            }
        }

        // Alert Deduplication Logic
        if ($deduplicationKey !== null) {
            $existing = TenantNotification::where('tenant_id', $tenantId)
                ->where('deduplication_key', $deduplicationKey)
                ->latest()
                ->first();

            if ($existing !== null) {
                // If recurring is not allowed and the active alert is either unread or created within the same cooldown
                if (!$allowRecurrence) {
                    if (!$existing->isRead() || $existing->created_at->isToday()) {
                        return $existing;
                    }
                }
            }
        }

        // Recursive sensitive credential scrubbing
        $cleanedData = $this->scrubSensitiveData($data);

        try {
            return TenantNotification::create([
                'tenant_id'         => $tenantId,
                'category'          => $category,
                'severity'          => $severity,
                'title'             => strip_tags($title),
                'message'           => strip_tags($message),
                'action_url'        => $actionUrl,
                'deduplication_key' => $deduplicationKey,
                'data'              => $cleanedData,
            ]);
        } catch (Throwable $e) {
            Log::error('TenantNotificationService::notify failed: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'category'  => $category,
                'title'     => $title,
                'exception' => get_class($e),
            ]);

            throw $e;
        }
    }

    /**
     * Retrieve paginated tenant notifications with bounded pagination and multi-criteria filters.
     */
    public function getNotifications(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $clampedPerPage = max(1, min(50, $perPage));

        $status = $filters['status'] ?? 'all';
        $query = TenantNotification::forTenant($user->id);

        // Status filter: 'unread', 'read', 'dismissed', 'all'
        if ($status === 'dismissed') {
            $query->dismissed();
        } elseif ($status === 'unread') {
            $query->active()->unread();
        } elseif ($status === 'read') {
            $query->active()->read();
        } else {
            // 'all': all active (non-dismissed) notifications
            $query->active();
        }

        // Category filter
        if (!empty($filters['category']) && in_array($filters['category'], self::VALID_CATEGORIES, true)) {
            $query->category($filters['category']);
        }

        // Severity filter
        if (!empty($filters['severity']) && in_array($filters['severity'], self::VALID_SEVERITIES, true)) {
            $query->severity($filters['severity']);
        }

        return $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($clampedPerPage);
    }

    /**
     * Efficiently count active unread notifications for a tenant.
     */
    public function getUnreadCount(User $user): int
    {
        return TenantNotification::forTenant($user->id)
            ->active()
            ->unread()
            ->count();
    }

    /**
     * Get aggregate notification counts by status, category, and severity.
     */
    public function getCounts(User $user): array
    {
        $unread = TenantNotification::forTenant($user->id)->active()->unread()->count();
        $read = TenantNotification::forTenant($user->id)->active()->read()->count();
        $dismissed = TenantNotification::forTenant($user->id)->dismissed()->count();
        $totalActive = $unread + $read;

        $categories = self::VALID_CATEGORIES;
        $byCategory = [];
        foreach ($categories as $cat) {
            $byCategory[$cat] = [
                'unread' => 0,
                'total'  => 0,
            ];
        }

        $catStats = TenantNotification::forTenant($user->id)
            ->active()
            ->selectRaw('category, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread_count, COUNT(*) as total_count')
            ->groupBy('category')
            ->get();

        foreach ($catStats as $stat) {
            if (isset($byCategory[$stat->category])) {
                $byCategory[$stat->category] = [
                    'unread' => (int) $stat->unread_count,
                    'total'  => (int) $stat->total_count,
                ];
            }
        }

        $bySeverity = [
            'critical' => 0,
            'warning'  => 0,
            'info'     => 0,
            'success'  => 0,
        ];

        $sevStats = TenantNotification::forTenant($user->id)
            ->active()
            ->unread()
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->get();

        foreach ($sevStats as $stat) {
            if (isset($bySeverity[$stat->severity])) {
                $bySeverity[$stat->severity] = (int) $stat->count;
            }
        }

        return [
            'unread'       => $unread,
            'read'         => $read,
            'dismissed'    => $dismissed,
            'total_active' => $totalActive,
            'by_category'  => $byCategory,
            'by_severity'  => $bySeverity,
        ];
    }

    /**
     * Retrieve tenant notification preferences metadata and current values.
     */
    public function getPreferences(User $user): array
    {
        return [
            'preferences'          => $user->getNotificationPreferences(),
            'immutable_categories' => self::IMMUTABLE_CATEGORIES,
            'category_labels'      => [
                'domain_dns'           => 'Domain & DNS Security',
                'security_abuse'       => 'Security Incidents & Abuse',
                'smtp_quota'           => 'SMTP Quota & Sending Limits',
                'billing_subscription' => 'Billing & Subscriptions',
                'mailbox_rule'         => 'Mailbox Rules & Auto-Replies',
                'system'               => 'System & Service Notices',
            ],
            'critical_notice'      => 'Critical security, DNS drift, and billing expiration alerts are mandatory and cannot be muted to ensure account security and compliance.',
        ];
    }

    /**
     * Update tenant notification preferences with validation and audit logging.
     *
     * @param User $user
     * @param array $preferences
     * @return array
     * @throws ValidationException
     */
    public function updatePreferences(User $user, array $preferences): array
    {
        foreach (self::IMMUTABLE_CATEGORIES as $imm) {
            if (array_key_exists($imm, $preferences) && $preferences[$imm] === false) {
                throw ValidationException::withMessages([
                    $imm => ["The {$imm} notification category is critical and cannot be disabled."],
                ]);
            }
        }

        $current = $user->getNotificationPreferences();
        $updated = [];

        foreach (self::VALID_CATEGORIES as $cat) {
            if (in_array($cat, self::IMMUTABLE_CATEGORIES, true)) {
                $updated[$cat] = true;
            } elseif (array_key_exists($cat, $preferences)) {
                $updated[$cat] = (bool) $preferences[$cat];
            } else {
                $updated[$cat] = (bool) ($current[$cat] ?? true);
            }
        }

        $beforeState = $current;
        $user->update(['notification_preferences' => $updated]);

        // Record persistent audit log for preference adjustment
        try {
            app(AuditService::class)->record(
                action: 'tenant.notification_preferences.update',
                entityType: 'User',
                entityId: $user->id,
                before: $beforeState,
                after: $updated,
                actor: $user,
                reason: 'Tenant updated notification preferences'
            );
        } catch (Throwable $e) {
            Log::warning('Failed to record audit log for notification preferences update: ' . $e->getMessage());
        }

        return $this->getPreferences($user);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(User $user, int $notificationId): ?TenantNotification
    {
        $notification = TenantNotification::forTenant($user->id)->find($notificationId);

        if (!$notification) {
            return null;
        }

        $notification->markAsRead();

        return $notification;
    }

    /**
     * Mark all active unread notifications as read for a tenant.
     */
    public function markAllAsRead(User $user): int
    {
        return TenantNotification::forTenant($user->id)
            ->active()
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * Dismiss a notification (archive).
     */
    public function dismiss(User $user, int $notificationId): ?TenantNotification
    {
        $notification = TenantNotification::forTenant($user->id)->find($notificationId);

        if (!$notification) {
            return null;
        }

        $notification->dismiss();

        return $notification;
    }

    /**
     * Restore a dismissed notification.
     */
    public function restore(User $user, int $notificationId): ?TenantNotification
    {
        $notification = TenantNotification::forTenant($user->id)->find($notificationId);

        if (!$notification) {
            return null;
        }

        $notification->restore();

        return $notification;
    }

    /**
     * Bounded cleanup of expired tenant notifications according to retention policy.
     *
     * Retention Policy Rules:
     * 1. Read notifications older than $readDays (default 90) are pruned.
     * 2. Dismissed notifications older than $dismissedDays (default 30) are pruned.
     * 3. Non-critical unread notifications older than 180 days are pruned.
     *
     * Invariants:
     * - Unresolved critical alerts are NEVER automatically deleted.
     * - Audit logs and abuse incidents are NEVER touched.
     *
     * @param int $readDays
     * @param int $dismissedDays
     * @param int $batchSize
     * @param bool $dryRun
     * @return array
     */
    public function pruneExpiredNotifications(
        int $readDays = 90,
        int $dismissedDays = 30,
        int $batchSize = 500,
        bool $dryRun = false
    ): array {
        $readCutoff = Carbon::now()->subDays($readDays);
        $dismissedCutoff = Carbon::now()->subDays($dismissedDays);
        $staleCutoff = Carbon::now()->subDays(180);

        // Eligible query
        $query = TenantNotification::query()
            ->where(function ($q) use ($readCutoff, $dismissedCutoff, $staleCutoff) {
                // Read notifications past retention window
                $q->where(function ($sub) use ($readCutoff) {
                    $sub->whereNotNull('read_at')
                        ->whereNull('dismissed_at')
                        ->where('read_at', '<', $readCutoff);
                })
                // Dismissed notifications past retention window
                ->orWhere(function ($sub) use ($dismissedCutoff) {
                    $sub->whereNotNull('dismissed_at')
                        ->where('dismissed_at', '<', $dismissedCutoff);
                })
                // Stale non-critical unread notifications past 180 days
                ->orWhere(function ($sub) use ($staleCutoff) {
                    $sub->whereNull('read_at')
                        ->whereNull('dismissed_at')
                        ->where('severity', '!=', 'critical')
                        ->where('created_at', '<', $staleCutoff);
                });
            });

        $totalEligible = (clone $query)->count();

        if ($dryRun) {
            return [
                'dry_run'        => true,
                'total_eligible' => $totalEligible,
                'pruned_count'   => 0,
            ];
        }

        $totalPruned = 0;
        while (true) {
            $ids = (clone $query)->limit($batchSize)->pluck('id')->all();
            if (empty($ids)) {
                break;
            }

            $deleted = TenantNotification::whereIn('id', $ids)->delete();
            $totalPruned += $deleted;

            if (count($ids) < $batchSize) {
                break;
            }
        }

        Log::info("TenantNotificationService::pruneExpiredNotifications completed: {$totalPruned} records pruned.");

        return [
            'dry_run'        => false,
            'total_eligible' => $totalEligible,
            'pruned_count'   => $totalPruned,
        ];
    }

    /**
     * Recursively scrub sensitive keys and credentials from notification data payload.
     */
    public function scrubSensitiveData(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $redactedKeys = [
            'password', 'secret', 'token', 'key', 'private_key', 'api_key',
            'hash', 'salt', 'trace', 'file', 'line', 'evidence',
        ];

        $cleaned = [];
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), $redactedKeys, true)) {
                continue;
            }

            if (is_array($v)) {
                $cleaned[$k] = $this->scrubSensitiveData($v);
            } else {
                $cleaned[$k] = $v;
            }
        }

        return $cleaned;
    }
}

