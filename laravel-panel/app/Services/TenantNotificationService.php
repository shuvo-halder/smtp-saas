<?php

namespace App\Services;

use App\Models\TenantNotification;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
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

        // Recursive sensitive credential scrubbing
        $cleanedData = $this->scrubSensitiveData($data);

        $dispatchClosure = function () use (
            $tenantId,
            $category,
            $severity,
            $title,
            $message,
            $actionUrl,
            $deduplicationKey,
            $cleanedData,
            $allowRecurrence
        ) {
            // Alert Deduplication Logic
            if ($deduplicationKey !== null) {
                $existing = TenantNotification::where('tenant_id', $tenantId)
                    ->where('deduplication_key', $deduplicationKey)
                    ->latest('id')
                    ->first();

                if ($existing !== null) {
                    // If recurring is not allowed and the active alert is either unread or created within the same cooldown
                    if (!$allowRecurrence) {
                        if (!$existing->isRead() || $existing->created_at?->isToday()) {
                            $this->recordDeduplicationOutcome($tenantId, $deduplicationKey, $existing->id);
                            return $existing;
                        }
                    }
                }
            }

            try {
                $notification = TenantNotification::create([
                    'tenant_id'         => $tenantId,
                    'category'          => $category,
                    'severity'          => $severity,
                    'title'             => strip_tags($title),
                    'message'           => strip_tags($message),
                    'action_url'        => $actionUrl,
                    'deduplication_key' => $deduplicationKey,
                    'data'              => $cleanedData,
                ]);

                $this->recordCreationOutcome($tenantId, $category, $severity);

                return $notification;
            } catch (Throwable $e) {
                $this->recordFailureOutcome($tenantId, $category, $severity, $e);
                Log::error('TenantNotificationService::notify failed: ' . $e->getMessage(), [
                    'tenant_id'         => $tenantId,
                    'category'          => $category,
                    'severity'          => $severity,
                    'title'             => $title,
                    'deduplication_key' => $deduplicationKey,
                    'exception'         => get_class($e),
                ]);

                throw $e;
            }
        };

        // If deduplication key is set, synchronize via cache lock to prevent concurrent duplicate inserts
        if ($deduplicationKey !== null) {
            $lockKey = "tenant_notif_lock:{$tenantId}:" . md5($deduplicationKey);
            try {
                $lock = Cache::lock($lockKey, 5);
                return $lock->block(3, $dispatchClosure);
            } catch (Throwable $e) {
                // If lock acquisition throws or is unsupported, fall back to direct execution
                return $dispatchClosure();
            }
        }

        return $dispatchClosure();
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
        int $staleDays = 180,
        int $batchSize = 500,
        bool $dryRun = false
    ): array {
        $readDays = max(1, $readDays);
        $dismissedDays = max(1, $dismissedDays);
        $staleDays = max(1, $staleDays);
        $batchSize = max(1, min(5000, $batchSize));

        $readCutoff = Carbon::now()->subDays($readDays);
        $dismissedCutoff = Carbon::now()->subDays($dismissedDays);
        $staleCutoff = Carbon::now()->subDays($staleDays);

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
                // Stale non-critical unread notifications past retention window
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
                'read_days'      => $readDays,
                'dismissed_days' => $dismissedDays,
                'stale_days'     => $staleDays,
                'batch_size'     => $batchSize,
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
            'read_days'      => $readDays,
            'dismissed_days' => $dismissedDays,
            'stale_days'     => $staleDays,
            'batch_size'     => $batchSize,
        ];
    }

    /**
     * Recursively scrub sensitive keys and normalize credentials and metadata.
     */
    public function scrubSensitiveData(?array $data, int $depth = 0): ?array
    {
        if ($data === null) {
            return null;
        }

        if ($depth > 10) {
            return ['_truncated' => 'Max metadata nesting depth exceeded'];
        }

        $redactedKeys = [
            'password', 'password_hash', 'secret', 'token', 'key', 'private_key', 'api_key',
            'hash', 'salt', 'trace', 'file', 'line', 'evidence', 'authorization', 'bearer',
        ];

        $cleaned = [];
        foreach ($data as $k => $v) {
            $keyStr = strtolower((string) $k);

            // Redact sensitive keys
            if (in_array($keyStr, $redactedKeys, true)) {
                continue;
            }

            // Handle arrays recursively
            if (is_array($v)) {
                $cleaned[$k] = $this->scrubSensitiveData($v, $depth + 1);
            } elseif (is_resource($v)) {
                // Sanitize resources to avoid JSON encoding failure
                $cleaned[$k] = '[Resource: ' . get_resource_type($v) . ']';
            } elseif (is_object($v)) {
                // Safely convert objects
                if (method_exists($v, 'toArray')) {
                    $cleaned[$k] = $this->scrubSensitiveData($v->toArray(), $depth + 1);
                } elseif ($v instanceof \JsonSerializable) {
                    $cleaned[$k] = $this->scrubSensitiveData((array) $v->jsonSerialize(), $depth + 1);
                } elseif (method_exists($v, '__toString')) {
                    $cleaned[$k] = mb_convert_encoding((string) $v, 'UTF-8', 'UTF-8');
                } else {
                    $cleaned[$k] = '[Object: ' . get_class($v) . ']';
                }
            } elseif (is_string($v)) {
                // Sanitize non-UTF-8 characters
                $cleaned[$k] = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            } else {
                $cleaned[$k] = $v;
            }
        }

        return $cleaned;
    }

    /**
     * Record deduplication outcome in operational logs and metrics.
     */
    protected function recordDeduplicationOutcome(int $tenantId, string $deduplicationKey, int $existingId): void
    {
        try {
            Cache::increment('notifications:deduplicated_24h_count');
            Log::info('TenantNotificationService: Notification deduplicated', [
                'tenant_id'         => $tenantId,
                'deduplication_key' => $deduplicationKey,
                'existing_id'       => $existingId,
                'deduplicated'      => true,
            ]);
        } catch (Throwable) {
            // Non-blocking telemetry
        }
    }

    /**
     * Record successful notification creation metrics.
     */
    protected function recordCreationOutcome(int $tenantId, string $category, string $severity): void
    {
        try {
            Cache::increment('notifications:created_24h_count');
        } catch (Throwable) {
            // Non-blocking telemetry
        }
    }

    /**
     * Record notification failure metrics.
     */
    protected function recordFailureOutcome(int $tenantId, string $category, string $severity, Throwable $e): void
    {
        try {
            Cache::increment('notifications:failures_24h_count');
            Cache::put('notifications:last_failure', [
                'occurred_at'   => now()->toIso8601String(),
                'tenant_id'     => $tenantId,
                'category'      => $category,
                'severity'      => $severity,
                'error_class'   => get_class($e),
                'error_message' => $e->getMessage(),
            ], 86400 * 3);
        } catch (Throwable) {
            // Non-blocking telemetry
        }
    }

    /**
     * Retrieve aggregated operational status and reliability telemetry for administrators.
     * Zero tenant notification content, titles, messages, or emails are exposed.
     *
     * @return array<string, mixed>
     */
    public function getOperationalStatus(): array
    {
        try {
            $total = TenantNotification::count();
            $unread = TenantNotification::active()->unread()->count();
            $criticalUnresolved = TenantNotification::active()->unread()->where('severity', 'critical')->count();
            $recent24hCreated = TenantNotification::where('created_at', '>=', now()->subHours(24))->count();
        } catch (Throwable) {
            $total = 0;
            $unread = 0;
            $criticalUnresolved = 0;
            $recent24hCreated = 0;
        }

        $deduplicated24h = (int) Cache::get('notifications:deduplicated_24h_count', 0);
        $failures24h = (int) Cache::get('notifications:failures_24h_count', 0);
        $lastFailure = Cache::get('notifications:last_failure');
        $lastPrune = Cache::get('notifications:prune:last_execution');

        $status = 'HEALTHY';
        if ($failures24h > 0 || ($lastPrune && ($lastPrune['status'] ?? '') === 'failed')) {
            $status = 'DEGRADED';
        }

        return [
            'status'    => $status,
            'timestamp' => now()->toIso8601String(),
            'summary'   => [
                'total_notifications'        => $total,
                'unread_notifications'       => $unread,
                'unresolved_critical_alerts' => $criticalUnresolved,
                'recent_24h_created'         => $recent24hCreated,
                'recent_24h_deduplicated'    => $deduplicated24h,
                'recent_24h_failures'        => $failures24h,
                'last_failure'               => $lastFailure,
            ],
            'pruning'   => [
                'last_run'                    => $lastPrune,
                'read_retention_days'         => 90,
                'dismissed_retention_days'    => 30,
                'stale_unread_retention_days' => 180,
                'critical_alerts_protected'   => true,
                'audit_abuse_protected'       => true,
            ],
            'scheduler' => [
                'task_registered'         => true,
                'expression'              => '30 4 * * *',
                'overlapping_lock_active' => Cache::has('notifications_prune_lock'),
            ],
        ];
    }
}

