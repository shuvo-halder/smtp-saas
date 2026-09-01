<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Domain;

class IdentifyTenant
{
    /**
     * Handle an incoming request.
     * Extracts tenant from subdomain and binds to container.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $origin = $request->header('Origin');
        
        // Extract host from origin if present, otherwise use request host
        $tenantHost = $host;
        if ($origin) {
            $parsedOrigin = parse_url($origin, PHP_URL_HOST);
            if ($parsedOrigin) {
                $tenantHost = $parsedOrigin;
            }
        }

        // Check if it's a tenant subdomain (e.g., something.mailsaas.com)
        $baseDomain = config('app.base_domain', 'mailsaas.com');
        
        if ($tenantHost !== $baseDomain && str_ends_with($tenantHost, '.' . $baseDomain)) {
            $subdomain = str_replace('.' . $baseDomain, '', $tenantHost);
            
            // Skip reserved subdomains
            if (!in_array($subdomain, ['www', 'panel', 'api'])) {
                // Find tenant domain
                $domain = Domain::where('domain_name', $tenantHost)
                    ->orWhere('domain_name', $subdomain . '.' . $baseDomain)
                    ->first();

                if ($domain) {
                    // Bind tenant domain and user to container
                    app()->instance('tenant', $domain->user);
                    app()->instance('tenant.domain', $domain);
                    app()->instance('tenant.user', $domain->user);
                }
            }
        }

        return $next($request);
    }
}
