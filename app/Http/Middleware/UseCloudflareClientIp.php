<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the client IP from Cloudflare's CF-Connecting-IP, but only when the request reached
 * the app through a trusted proxy (see config/trustedproxy.php). Cloudflare overwrites that
 * header on every request, while X-Forwarded-For keeps whatever the client sent first.
 * A request from anywhere else keeps its socket address and the header is ignored.
 */
final class UseCloudflareClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $cfIp = $request->headers->get('CF-Connecting-IP');

        if (is_string($cfIp) && filter_var($cfIp, FILTER_VALIDATE_IP) !== false && $request->isFromTrustedProxy()) {
            $request->headers->set('X-Forwarded-For', $cfIp);
        } else {
            $request->headers->remove('CF-Connecting-IP');
        }

        return $next($request);
    }
}
