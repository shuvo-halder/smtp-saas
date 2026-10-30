<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MailDeliveryRecordResource;
use App\Models\ScheduledTaskRun;
use App\Services\Health\SystemHealthService;
use App\Services\MailDeliveryService;
use App\Services\TenantNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSystemHealthApiController extends Controller
{
    public function __construct(
        protected SystemHealthService $healthService
    ) {}

    /**
     * Authenticated administrative endpoint for detailed system health diagnostics.
     * Guarded by EnsureAdmin and RequireAdminPermission:admin.stats.read.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->healthService->getHealth(), 200);
    }

    /**
     * Authenticated administrative endpoint for paginated scheduled task execution history.
     */
    public function tasks(Request $request): JsonResponse
    {
        $query = ScheduledTaskRun::query()->latest('started_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('task_name', 'like', "%{$search}%")
                  ->orWhere('failure_summary', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->where('started_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('started_at', '<=', $request->input('date_to'));
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 50);
        $paginated = $query->paginate($perPage);

        return response()->json($paginated, 200);
    }

    /**
     * Authenticated administrative endpoint for tenant notification delivery reliability & operational monitoring.
     * Guarded by EnsureAdmin and RequireAdminPermission:admin.stats.read.
     * Exposes ZERO tenant notification content, titles, messages, or emails.
     */
    public function notifications(TenantNotificationService $notificationService): JsonResponse
    {
        return response()->json($notificationService->getOperationalStatus(), 200);
    }

    /**
     * Authenticated administrative endpoint for mail delivery queue operational status.
     * Guarded by EnsureAdmin and RequireAdminPermission:admin.stats.read.
     * Exposes ZERO payload content, tokens, or recipient PII.
     */
    public function mailQueue(MailDeliveryService $deliveryService): JsonResponse
    {
        return response()->json($deliveryService->getOperationalStatus(), 200);
    }

    /**
     * Authenticated administrative endpoint for paginated mail delivery records.
     * Guarded by EnsureAdmin and RequireAdminPermission:admin.stats.read.
     */
    public function mailDeliveries(Request $request, MailDeliveryService $deliveryService): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginated = $deliveryService->getAdminDeliveries(
            $request->only(['status', 'message_type', 'date_from', 'date_to', 'tenant_id']),
            $perPage
        );

        return MailDeliveryRecordResource::collection($paginated)->response();
    }

    /**
     * Authenticated administrative endpoint to retry a failed or cancelled mail delivery.
     * Guarded by EnsureAdmin and RequireAdminPermission:admin.stats.read.
     */
    public function retryMailDelivery(int $id, MailDeliveryService $deliveryService): JsonResponse
    {
        $record = $deliveryService->retry($id);

        return response()->json([
            'message' => 'Mail delivery queued for retry.',
            'delivery' => new MailDeliveryRecordResource($record),
        ], 200);
    }

    /**
     * Authenticated administrative endpoint to cancel a pending queued mail delivery.
     * Guarded by EnsureAdmin and RequireAdminPermission:admin.stats.read.
     */
    public function cancelMailDelivery(int $id, MailDeliveryService $deliveryService): JsonResponse
    {
        $record = $deliveryService->cancel($id);

        return response()->json([
            'message' => 'Mail delivery cancelled.',
            'delivery' => new MailDeliveryRecordResource($record),
        ], 200);
    }
}
