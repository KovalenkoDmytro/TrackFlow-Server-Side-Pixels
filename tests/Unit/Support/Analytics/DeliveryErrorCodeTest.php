<?php

declare(strict_types=1);

use App\Support\Analytics\DeliveryErrorCode;

describe('DeliveryErrorCode', function (): void {
    it('reads the primary code from a partial failure JSON body', function (): void {
        $body = '{"codes":["CONVERSION_PRECEDES_EVENT","OTHER"],"message":"The conversion precedes the event."}';

        expect(DeliveryErrorCode::fromResponseBody($body))->toBe('CONVERSION_PRECEDES_EVENT')
            ->and(DeliveryErrorCode::message($body))->toBe('The conversion precedes the event.');
    });

    it('recognises Meta access token errors in plain messages', function (): void {
        expect(DeliveryErrorCode::fromResponseBody('Error validating access token: Session has expired'))->toBe('META_ACCESS_TOKEN');
    });

    it('finds an UPPER_SNAKE code inside a plain message', function (): void {
        expect(DeliveryErrorCode::fromResponseBody('Google Ads API error: USER_PERMISSION_DENIED for customer'))->toBe('USER_PERMISSION_DENIED');
    });

    it('falls back to UNKNOWN', function (): void {
        expect(DeliveryErrorCode::fromResponseBody(null))->toBe('UNKNOWN')
            ->and(DeliveryErrorCode::fromResponseBody('real problem'))->toBe('UNKNOWN')
            ->and(DeliveryErrorCode::fromResponseBody('{"codes":[],"message":"x"}'))->toBe('UNKNOWN');
    });
});
