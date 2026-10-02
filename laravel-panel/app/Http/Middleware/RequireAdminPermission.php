<?php

namespace App\Http\Middleware;

use App\Services\SecurityAuditLogger;
use Closure;
use Illuminate\Http\Request;

class RequireAdminPermission
{
    /**
     * Handle an incoming request.
     * Enforces granular RBAC permissions with fail-closed security and denial logging.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $permission
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();

        // 1. Authentication check
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // 2. Suspended administrator check
        if ($user->status === 'suspended') {
            SecurityAuditLogger::logDenial(
                $request,
                'Suspended administrator denied access',
                $permission
            );

            return response()->json(['message' => 'Admin access required.'], 403);
        }

        // 3. Granular RBAC permission check (guard: web per RBAC-DEC-01)
        if (! $user->hasPermissionTo($permission, 'web')) {
            SecurityAuditLogger::logDenial(
                $request,
                "Missing required permission [{$permission}]",
                $permission
            );

            return response()->json([
                'message' => "Forbidden: missing required permission [{$permission}]."
            ], 403);
        }

        return $next($request);
    }
}
