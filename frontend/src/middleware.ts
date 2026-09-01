import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';

export function middleware(req: NextRequest) {
  const url = req.nextUrl.clone();
  
  // Get hostname of request (e.g. tenant1.mailsaas.com, www.mailsaas.com)
  const hostname = req.headers.get('host') || '';

  // Allowed domains / non-tenant subdomains
  const reservedSubdomains = ['www', 'panel', 'api'];
  
  // Extract subdomain assuming the format subdomain.domain.com
  // For local testing, it could be tenant1.localhost:3000
  let subdomain = null;
  const hostParts = hostname.split('.');
  
  // If the host has more than 2 parts (excluding port if localhost)
  // For example: tenant1.mailsaas.com -> 3 parts
  // tenant1.localhost:3000 -> 2 parts, but split by '.' gives tenant1 and localhost:3000
  if (hostParts.length > 2 || (hostname.includes('localhost') && hostParts.length > 1)) {
    subdomain = hostParts[0];
  }

  // Rewrite to tenant route if it's a valid tenant subdomain
  if (subdomain && !reservedSubdomains.includes(subdomain)) {
    url.pathname = `/(tenant)/${subdomain}${url.pathname}`;
    return NextResponse.rewrite(url);
  }

  return NextResponse.next();
}

export const config = {
  matcher: [
    /*
     * Match all request paths except for the ones starting with:
     * - api (API routes)
     * - _next/static (static files)
     * - _next/image (image optimization files)
     * - favicon.ico (favicon file)
     */
    '/((?!api|_next/static|_next/image|favicon.ico).*)',
  ],
};
