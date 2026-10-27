<?php

namespace App\Services;

use App\Models\TenantNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
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

    public const VALID_SEVERITIES = [
        'info',
        'warning',
        'critical',
        'success',
    ];

    /**
     * Dispatch an in-app tenant notification with atomic deduplication.
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

        try {
            return TenantNotification::create([
                'tenant_id'         => $tenantId,
                'category'          => $category,
                'severity'          => $severity,
                'title'             => $title,
                'message'           => $message,
                'action_url'        => $actionUrl,
                'deduplication_key' => $deduplicationKey,
                'data'              => $data,
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

        $query = TenantNotification::forTenant($user->id)->active();

        // Status filter: 'unread' (default if specified), 'read', 'all'
        $status = $filters['status'] ?? null;
        if ($status === 'unread') {
            $query->unread();
        } elseif ($status === 'read') {
            $query->read();
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
}
