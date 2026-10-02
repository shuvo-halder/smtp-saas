<?php

namespace App\Http\Middleware;

use App\Services\SecurityAuditLogger;
use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    /**
     * Handle an incoming request.
     * Perimeter guard for all administrative routes.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->status === 'suspended') {
            SecurityAuditLogger::logDenial($request, 'Suspended administrator denied admin access');
            return response()->json(['message' => 'Admin access required.'], 403);
        }

        $hasAdminRole = method_exists($user, 'roles') && $user->roles()->exists();
        if (! $user->is_admin && ! $hasAdminRole) {
            SecurityAuditLogger::logDenial($request, 'Non-admin user denied admin access');
            return response()->json(['message' => 'Admin access required.'], 403);
        }

        return $next($request);
    }
}
