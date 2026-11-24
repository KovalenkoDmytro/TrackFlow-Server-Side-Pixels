<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects request bodies larger than the given number of kilobytes with 413, before
 * validation or JSON decoding. Checks both Content-Length and the actual body.
 */
final class LimitRequestBodySize
{
    public function handle(Request $request, Closure $next, string $kilobytes = '16'): Response
    {
        $limit = (int) $kilobytes * 1024;
        $declared = (int) $request->headers->get('Content-Length', '0');

        if ($declared > $limit || strlen((string) $request->getContent()) > $limit) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        return $next($request);
    }
}
