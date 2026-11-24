<?php

declare(strict_types=1);

use App\Logging\SecretProcessor;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

function secretRecord(string $message, array $context = []): LogRecord
{
    return (new SecretProcessor)(new LogRecord(new DateTimeImmutable, 'test', Level::Warning, $message, $context));
}

it('masks well-known token formats and credential fields', function (string $input, string $secret): void {
    $out = SecretRedactor::redact($input);

    expect($out)->not->toContain($secret)->toContain(SecretRedactor::REDACTED);
})->with([
    'shopify offline token' => ['token shp'.'at_0123456789abcdef0123456789abcdef used', 'shp'.'at_0123456789abcdef'],
    'shopify online token' => ['shp'.'ua_abcdef0123456789abcdef0123456789', 'shp'.'ua_abcdef'],
    'meta token' => ['Error for EA'.'AGm0PX4ZCpsBAKZB7abcdefghij1234567890 ok', 'EA'.'AGm0PX4ZCpsBAKZB7abcdefghij'],
    'google access token' => ['Bearer ya2'.'9.a0AfH6SMBx-secret_value', 'a0AfH6SMBx'],
    'google refresh token' => ['refresh 1/'.'/0gAbCdEfGhIjKlMnOpQrStUvWxYz', '0gAbCdEfGhIjKl'],
    'authorization header' => ['Authorization: Bearer abc.def.ghi', 'abc.def.ghi'],
    'shopify header' => ['X-Shopify-Access-Token: tok_abc123', 'tok_abc123'],
    'json field' => ['{"access_token":"tok-123","expires_in":3600}', 'tok-123'],
    'form body' => ['grant_type=refresh_token&refresh_token=rt-999&client_id=1', 'rt-999'],
    'php array dump' => ["'api_secret' => 'S3CR3T'", 'S3CR3T'],
    'query string' => ['https://x.test/mp/collect?measurement_id=G-1&api_secret=S3CR3T', 'S3CR3T'],
]);

it('keeps ordinary text untouched', function (): void {
    $text = 'Google Ads upload failed for order 1001: CONVERSION_PRECEDES_EVENT (measurement_id=G-ABC)';

    expect(SecretRedactor::redact($text))->toBe($text);
});

it('redacts message, context values, sensitive keys and nested arrays of a log record', function (): void {
    $record = secretRecord('Shopify call with shp'.'at_0123456789abcdef0123', [
        'shop' => 'demo.myshopify.com',
        'access_token' => 'plain-token',
        'refresh_token' => 'rt',
        'has_access_token' => true,
        'request' => ['headers' => ['Authorization' => 'Bearer abc'], 'url' => 'https://g.test/?api_secret=xyz'],
    ]);

    expect($record->message)->toBe('Shopify call with [redacted]')
        ->and($record->context['shop'])->toBe('demo.myshopify.com')
        ->and($record->context['access_token'])->toBe('[redacted]')
        ->and($record->context['refresh_token'])->toBe('[redacted]')
        ->and($record->context['has_access_token'])->toBeTrue()
        ->and($record->context['request']['headers']['Authorization'])->toBe('[redacted]')
        ->and($record->context['request']['url'])->toBe('https://g.test/?api_secret=[redacted]');
});

it('replaces an exception only when its text contains a secret', function (): void {
    $plain = new RuntimeException('timeout');
    $leaky = new RuntimeException('cURL error for https://graph.test/events?access_token=EA'.'Asecretsecretsecretsecret123');

    expect(secretRecord('x', ['exception' => $plain])->context['exception'])->toBe($plain);

    $out = secretRecord('x', ['exception' => $leaky])->context['exception'];
    expect($out)->toBeString()->toContain('RuntimeException')->not->toContain('EAAsecret');
});

it('is applied to every configured log channel', function (string $channel): void {
    $handler = new TestHandler;
    $logger = Log::channel($channel);
    $logger->getLogger()->setHandlers([$handler]);

    $logger->error('leak shp'.'at_abcdef0123456789abcdef', ['api_secret' => 'S3CR3T']);

    expect($handler->getRecords()[0]->message)->toBe('leak [redacted]')
        ->and($handler->getRecords()[0]->context['api_secret'])->toBe('[redacted]');
})->with(['single', 'daily', 'stderr', 'syslog', 'errorlog', 'stack']);
