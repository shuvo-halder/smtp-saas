<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MailDeliveryRecordResource;
use App\Models\ScheduledTaskRun;
use App\Services\AuditService;
use App\Services\Health\SystemHealthService;
use App\Services\MailDeliveryService;
use App\Services\TenantNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function retryMailDelivery(int $id, MailDeliveryService $deliveryService, Request $request): JsonResponse
    {
        try {
            $actor = $request->user();
            $record = $deliveryService->retry($id, $actor);

            try {
                app(AuditService::class)->record(
                    action: 'admin.mail_delivery.retry',
                    entityType: 'MailDeliveryRecord',
                    entityId: $record->id,
                    before: ['status' => 'failed_or_cancelled'],
                    after: ['status' => $record->status, 'attempts' => $record->attempts],
                    actor: $actor,
                    reason: 'Admin manually queued mail delivery for retry',
                    request: $request
                );
            } catch (Throwable $e) {
                Log::warning('Failed to record audit log for mail delivery retry: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Mail delivery queued for retry.',
                'delivery' => new MailDeliveryRecordResource($record),
            ], 200);
        } catch (\App\Exceptions\MailDelivery\InvalidDeliveryStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Authenticated administrative endpoint to cancel a pending queued mail delivery.
     * Guarded by EnsureAdmin and RequireAdminPermission:admin.stats.read.
     */
    public function cancelMailDelivery(int $id, MailDeliveryService $deliveryService, Request $request): JsonResponse
    {
        try {
            $actor = $request->user();
            $record = $deliveryService->cancel($id, $actor);

            try {
                app(AuditService::class)->record(
                    action: 'admin.mail_delivery.cancel',
                    entityType: 'MailDeliveryRecord',
                    entityId: $record->id,
                    before: ['status' => 'queued'],
                    after: ['status' => $record->status],
                    actor: $actor,
                    reason: 'Admin cancelled pending mail delivery',
                    request: $request
                );
            } catch (Throwable $e) {
                Log::warning('Failed to record audit log for mail delivery cancellation: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Mail delivery cancelled.',
                'delivery' => new MailDeliveryRecordResource($record),
            ], 200);
        } catch (\App\Exceptions\MailDelivery\InvalidDeliveryStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
