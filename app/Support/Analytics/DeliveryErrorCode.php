<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Extracts a stable error code and a short readable message from a stored
 * platform_deliveries.response_body.
 *
 * Partial failures are stored as JSON {"codes": [...], "message": "..."} (see PartialFailure::toJson()).
 * Exceptions are stored as a plain (already redacted) message.
 */
final class DeliveryErrorCode
{
    public const string UNKNOWN = 'UNKNOWN';

    public const string META_ACCESS_TOKEN = 'META_ACCESS_TOKEN';

    private const int MAX_MESSAGE_LENGTH = 300;

    public static function fromResponseBody(?string $body): string
    {
        if ($body === null || trim($body) === '') {
            return self::UNKNOWN;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $codes = $decoded['codes'] ?? null;
            if (is_array($codes) && isset($codes[0]) && is_string($codes[0]) && $codes[0] !== '') {
                return $codes[0];
            }
            $body = is_string($decoded['message'] ?? null) ? $decoded['message'] : $body;
        }

        if (preg_match('/access token|OAuthException/i', $body) === 1) {
            return self::META_ACCESS_TOKEN;
        }

        if (preg_match('/\b([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)\b/', $body, $matches) === 1) {
            return $matches[1];
        }

        return self::UNKNOWN;
    }

    public static function message(?string $body): string
    {
        if ($body === null) {
            return '';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) && is_string($decoded['message'] ?? null)) {
            $body = $decoded['message'];
        }

        return mb_substr(trim($body), 0, self::MAX_MESSAGE_LENGTH);
    }
}
