<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->isSubscriptionActive()) {
            return response()->json(['message' => 'Active subscription required.'], 403);
        }

        return $next($request);
    }
}
