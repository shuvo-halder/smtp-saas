<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MailDeliveryRecordResource;
use App\Models\MailDeliveryRecord;
use App\Services\MailDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MailDeliveryApiController extends Controller
{
    public function __construct(
        protected MailDeliveryService $deliveryService
    ) {}

    /**
     * List paginated mail deliveries for the authenticated tenant.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page'     => 'nullable|integer|min:1|max:50',
            'status'       => 'nullable|string|in:queued,processing,sent,failed,cancelled',
            'message_type' => 'nullable|string|in:' . implode(',', MailDeliveryService::MESSAGE_TYPES),
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $deliveries = $this->deliveryService->getTenantDeliveries(
            $request->user(),
            $validated,
            $perPage
        );

        return MailDeliveryRecordResource::collection($deliveries);
    }

    /**
     * Inspect a specific mail delivery record owned by the authenticated tenant.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $record = MailDeliveryRecord::forTenant($request->user()->id)->find($id);

        if (!$record) {
            return response()->json([
                'message' => 'Mail delivery record not found.',
            ], 404);
        }

        return response()->json([
            'data' => new MailDeliveryRecordResource($record),
        ]);
    }
}
