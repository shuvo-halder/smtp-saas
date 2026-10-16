<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Services\TenantSmtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantSmtpApiController extends Controller
{
    public function __construct(
        protected TenantSmtpService $service
    ) {}

    /**
     * GET /api/smtp/settings
     */
    public function settings(Request $request): JsonResponse
    {
        return response()->json($this->service->getSettings($request->user()));
    }

    /**
     * GET /api/smtp/usage
     */
    public function usage(Request $request): JsonResponse
    {
        return response()->json($this->service->getUsage($request->user()));
    }

    /**
     * GET /api/smtp/credentials
     */
    public function credentials(Request $request): JsonResponse
    {
        return response()->json($this->service->getCredentials($request->user()));
    }

    /**
     * POST /api/smtp/credentials/{mailbox}/rotate-password
     */
    public function rotatePassword(Request $request, Mailbox $mailbox): JsonResponse
    {
        $result = $this->service->rotatePassword($request->user(), $mailbox, $request);

        return response()->json($result);
    }

    /**
     * GET /api/smtp/activity
     */
    public function activity(Request $request)
    {
        return $this->service->getActivityLogs($request->user(), $request);
    }

    /**
     * GET /api/smtp/diagnostics
     */
    public function diagnostics(Request $request): JsonResponse
    {
        return response()->json($this->service->getDiagnostics($request->user()));
    }
}
