<?php

declare(strict_types=1);

/*
 * Proxies whose X-Forwarded-* headers are trusted. In production the only hop in front of
 * Octane is the local nginx (fed by cloudflared), so only loopback is trusted. Trusting '*'
 * would let any caller set their own IP with a forged X-Forwarded-For.
 */
return [
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),
];
