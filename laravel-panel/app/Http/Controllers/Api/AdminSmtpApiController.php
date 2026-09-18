<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminSmtpMailboxResource;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Admin\AdminSmtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class AdminSmtpApiController extends Controller
{
    public function __construct(
        protected AdminSmtpService $service
    ) {}

    /**
     * GET /api/admin/smtp/overview
     */
    public function overview(): JsonResponse
    {
        return response()->json($this->service->getOverview());
    }

    /**
     * GET /api/admin/smtp/tenants
     */
    public function tenants(Request $request)
    {
        return $this->service->getTenants($request);
    }

    /**
     * GET /api/admin/smtp/tenants/{user}
     */
    public function showTenant(User $user): JsonResponse
    {
        return response()->json($this->service->getTenantDetail($user));
    }

    /**
     * GET /api/admin/smtp/mailboxes
     */
    public function mailboxes(Request $request)
    {
        return $this->service->getMailboxes($request);
    }

    /**
     * GET /api/admin/smtp/abuse
     */
    public function abuse(): JsonResponse
    {
        return response()->json($this->service->getAbuseWarnings());
    }

    /**
     * POST /api/admin/smtp/mailboxes/{mailbox}/toggle
     */
    public function toggleMailbox(Request $request, Mailbox $mailbox)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $updated = $this->service->toggleMailbox(
                $mailbox,
                $validated['reason'] ?? null,
                $request->user()
            );

            return response()->json(new AdminSmtpMailboxResource($updated));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Failed to toggle mailbox status.'], 500);
        }
    }

    /**
     * POST /api/admin/smtp/mailboxes/{mailbox}/reset-bounces
     */
    public function resetBounces(Request $request, Mailbox $mailbox): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->service->resetConsecutiveBounces(
                $mailbox,
                $validated['reason'] ?? null,
                $request->user()
            );

            return response()->json($result);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Failed to reset consecutive bounces.'], 500);
        }
    }

    /**
     * POST /api/admin/smtp/mailboxes/{mailbox}/reset-password
     */
    public function resetPassword(Request $request, Mailbox $mailbox): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'nullable|string|min:8',
            'reason'   => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->service->resetPassword(
                $mailbox,
                $validated['password'] ?? null,
                $validated['reason'] ?? null,
                $request->user()
            );

            return response()->json($result);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Failed to reset mailbox password.'], 500);
        }
    }
}
