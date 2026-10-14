<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScheduledTaskRun;
use App\Services\Health\SystemHealthService;
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
}
