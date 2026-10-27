<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantSecurityDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardApiController extends Controller
{
    /**
     * GET /api/dashboard
     * Retrieve unified tenant dashboard with domain security, SMTP telemetry, and abuse incidents.
     */
    public function index(Request $request, TenantSecurityDashboardService $dashboardService): JsonResponse
    {
        $user = $request->user()->load('plan');
        $data = $dashboardService->getDashboardData($user, $request);

        return response()->json($data);
    }

    /**
     * GET /api/security/incidents
     * Bounded, paginated list of security and abuse incidents for the authenticated tenant.
     */
    public function securityIncidents(Request $request, TenantSecurityDashboardService $dashboardService)
    {
        $user = $request->user();

        return $dashboardService->getTenantIncidents($user, $request);
    }
}
