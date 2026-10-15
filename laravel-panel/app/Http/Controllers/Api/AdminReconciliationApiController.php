<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Reconciliation\TenantUsageReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReconciliationApiController extends Controller
{
    public function __construct(
        protected TenantUsageReconciliationService $reconciliationService
    ) {}

    /**
     * Retrieve platform-wide or filtered tenant usage, quota, and billing reconciliation report.
     */
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'tenant_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $report = $this->reconciliationService->reconcile(
            tenantId: isset($validated['tenant_id']) ? (int) $validated['tenant_id'] : null,
            date: $validated['date'] ?? null
        );

        return response()->json($report);
    }

    /**
     * Retrieve reconciliation drill-down for a specific tenant.
     */
    public function tenantReport(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $report = $this->reconciliationService->reconcile(
            tenantId: $user->id,
            date: $validated['date'] ?? null
        );

        $tenantData = $report['tenants'][0] ?? null;

        return response()->json([
            'checked_at' => $report['checked_at'],
            'reconciliation_date' => $report['reconciliation_date'],
            'telemetry_available' => $report['telemetry_available'],
            'tenant' => $tenantData,
            'discrepancies' => $report['discrepancies'],
        ]);
    }
}
