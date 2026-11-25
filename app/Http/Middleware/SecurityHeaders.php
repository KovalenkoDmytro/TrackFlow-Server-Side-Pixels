<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response.
 *
 * Framing: the app is embedded in the Shopify admin, so X-Frame-Options is never sent and
 * framing is governed by CSP frame-ancestors. ShopifyIframeProtection (web group) already
 * sets a per-shop policy; this middleware only adds the generic Shopify policy where no CSP
 * was set (API, webhooks, errors), and never overrides an existing one.
 */
final class SecurityHeaders
{
    public const string DEFAULT_FRAME_ANCESTORS = 'frame-ancestors https://*.myshopify.com https://admin.shopify.com';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->remove('X-Frame-Options');
        $headers->remove('X-Powered-By');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::DEFAULT_FRAME_ANCESTORS);
        }

        if ($request->isSecure() && ! app()->environment('local')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
