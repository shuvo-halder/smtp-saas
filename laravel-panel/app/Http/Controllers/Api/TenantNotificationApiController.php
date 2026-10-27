<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantNotificationResource;
use App\Services\TenantNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TenantNotificationApiController extends Controller
{
    public function __construct(
        protected TenantNotificationService $notificationService
    ) {}

    /**
     * List paginated tenant notifications with status, category, and severity filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
            'status'   => 'nullable|string|in:unread,read,dismissed,all',
            'category' => 'nullable|string|in:' . implode(',', TenantNotificationService::VALID_CATEGORIES),
            'severity' => 'nullable|string|in:' . implode(',', TenantNotificationService::VALID_SEVERITIES),
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $filters = [
            'status'   => $validated['status'] ?? null,
            'category' => $validated['category'] ?? null,
            'severity' => $validated['severity'] ?? null,
        ];

        $notifications = $this->notificationService->getNotifications(
            $request->user(),
            $filters,
            $perPage
        );

        return TenantNotificationResource::collection($notifications);
    }

    /**
     * Get aggregate notification counts by status, category, and severity.
     */
    public function counts(Request $request): JsonResponse
    {
        $counts = $this->notificationService->getCounts($request->user());

        return response()->json($counts);
    }

    /**
     * Retrieve tenant notification preferences and category metadata.
     */
    public function preferences(Request $request): JsonResponse
    {
        $data = $this->notificationService->getPreferences($request->user());

        return response()->json($data);
    }

    /**
     * Update tenant notification preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain_dns'           => 'nullable|boolean',
            'security_abuse'       => 'nullable|boolean',
            'smtp_quota'           => 'nullable|boolean',
            'billing_subscription' => 'nullable|boolean',
            'mailbox_rule'         => 'nullable|boolean',
            'system'               => 'nullable|boolean',
        ]);

        $result = $this->notificationService->updatePreferences($request->user(), $validated);

        return response()->json([
            'message'     => 'Notification preferences updated successfully.',
            'preferences' => $result['preferences'],
            'data'        => $result,
        ]);
    }

    /**
     * Get active unread notifications count for the authenticated tenant.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->getUnreadCount($request->user());

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = $this->notificationService->markAsRead($request->user(), $id);

        if (!$notification) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        return response()->json([
            'message'      => 'Notification marked as read.',
            'notification' => new TenantNotificationResource($notification),
        ]);
    }

    /**
     * Mark all active unread notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $updatedCount = $this->notificationService->markAllAsRead($request->user());

        return response()->json([
            'message'       => 'All notifications marked as read.',
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Dismiss/archive a notification.
     */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $notification = $this->notificationService->dismiss($request->user(), $id);

        if (!$notification) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Notification dismissed successfully.',
        ]);
    }

    /**
     * Restore a dismissed notification.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $notification = $this->notificationService->restore($request->user(), $id);

        if (!$notification) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        return response()->json([
            'message'      => 'Notification restored successfully.',
            'notification' => new TenantNotificationResource($notification),
        ]);
    }
}
