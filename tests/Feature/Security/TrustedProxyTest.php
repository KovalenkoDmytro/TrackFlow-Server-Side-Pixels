<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/__test/ip', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secure' => $request->secure(),
    ]));
});

function ipFrom(object $test, string $remote, array $headers): array
{
    return $test->withServerVariables(['REMOTE_ADDR' => $remote])
        ->withHeaders($headers)
        ->getJson('/__test/ip')
        ->assertOk()
        ->json();
}

it('ignores a forged X-Forwarded-For from an untrusted address', function (): void {
    expect(ipFrom($this, '198.51.100.20', ['X-Forwarded-For' => '1.2.3.4'])['ip'])->toBe('198.51.100.20');
});

it('ignores CF-Connecting-IP from an untrusted address', function (): void {
    expect(ipFrom($this, '198.51.100.20', ['CF-Connecting-IP' => '1.2.3.4'])['ip'])->toBe('198.51.100.20');
});

it('uses CF-Connecting-IP behind the local proxy, not the client-controlled X-Forwarded-For', function (): void {
    $data = ipFrom($this, '127.0.0.1', [
        'X-Forwarded-For' => '6.6.6.6',
        'CF-Connecting-IP' => '203.0.113.9',
        'X-Forwarded-Proto' => 'https',
    ]);

    expect($data['ip'])->toBe('203.0.113.9')->and($data['secure'])->toBeTrue();
});

it('trusts IPv6 loopback too', function (): void {
    expect(ipFrom($this, '::1', ['CF-Connecting-IP' => '2001:db8::5'])['ip'])->toBe('2001:db8::5');
});

it('ignores an invalid CF-Connecting-IP and falls back to the rightmost untrusted hop', function (): void {
    expect(ipFrom($this, '127.0.0.1', ['CF-Connecting-IP' => 'not-an-ip', 'X-Forwarded-For' => '6.6.6.6, 203.0.113.9'])['ip'])->toBe('203.0.113.9');
});
