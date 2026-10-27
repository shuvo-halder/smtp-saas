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
            'status'   => 'nullable|string|in:unread,read,all',
            'category' => 'nullable|string|max:50',
            'severity' => 'nullable|string|max:20',
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
}
