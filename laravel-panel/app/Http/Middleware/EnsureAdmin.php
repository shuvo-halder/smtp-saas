<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->is_admin) {
            return response()->json(['message' => 'Admin access required.'], 403);
        }

        return $next($request);
    }
}
