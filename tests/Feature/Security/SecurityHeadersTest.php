<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;

it('adds the baseline headers and allows framing only by the Shopify admin', function (): void {
    $response = $this->get('/up');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', SecurityHeaders::DEFAULT_FRAME_ANCESTORS)
        ->assertHeaderMissing('X-Frame-Options');

    expect(SecurityHeaders::DEFAULT_FRAME_ANCESTORS)
        ->toContain('https://*.myshopify.com')
        ->toContain('https://admin.shopify.com');
});

it('sends HSTS only over HTTPS', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->get('http://localhost/up')
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://localhost/up')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('keeps the per-shop frame-ancestors policy set by ShopifyIframeProtection', function (): void {
    $policy = 'frame-ancestors https://shop-a.myshopify.com https://admin.shopify.com';

    $response = (new SecurityHeaders)->handle(Request::create('/'), function () use ($policy) {
        $response = response('ok');
        $response->headers->set('Content-Security-Policy', $policy);
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    });

    expect($response->headers->get('Content-Security-Policy'))->toBe($policy)
        ->and($response->headers->has('X-Frame-Options'))->toBeFalse();
});
