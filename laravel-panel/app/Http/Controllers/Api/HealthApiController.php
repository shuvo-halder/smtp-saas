<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Health\SystemHealthService;
use Illuminate\Http\JsonResponse;

class HealthApiController extends Controller
{
    public function __construct(
        protected SystemHealthService $healthService
    ) {}

    /**
     * Minimal public liveness probe.
     * Reveals zero infrastructure secrets, credentials, or internal topology.
     */
    public function liveness(): JsonResponse
    {
        return response()->json($this->healthService->getLiveness(), 200);
    }
}
