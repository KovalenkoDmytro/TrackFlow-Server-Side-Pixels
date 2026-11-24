<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\Platform;

/**
 * Extracts a stable error code and a short readable message from a stored
 * platform_deliveries.response_body.
 *
 * Partial failures are stored as JSON {"codes": [...], "message": "..."} (see PartialFailure::toJson()).
 * Exceptions are stored as a plain (already redacted) message.
 *
 * Plain-message classification depends on the platform that produced the row: a Google
 * OAuth refresh failure ("Failed to obtain access token from Google OAuth [...]") also
 * contains "access token", so it must never be read as a Meta token problem.
 */
final class DeliveryErrorCode
{
    public const string UNKNOWN = 'UNKNOWN';

    public const string META_ACCESS_TOKEN = 'META_ACCESS_TOKEN';

    public const string GOOGLE_ADS_AUTH = 'GOOGLE_ADS_AUTH';

    /** Token or sign-in failure on a platform without a dedicated code. */
    public const string ACCESS_TOKEN_INVALID = 'ACCESS_TOKEN_INVALID';

    /**
     * Sign-in / token failures. Reconnecting the integration fixes them, so failures from before
     * the latest reconnect (platform_integrations.connected_at) are no longer counted.
     */
    public const array AUTH_CODES = [self::GOOGLE_ADS_AUTH, self::META_ACCESS_TOKEN, self::ACCESS_TOKEN_INVALID];

    public static function isAuthCode(string $code): bool
    {
        return in_array($code, self::AUTH_CODES, true);
    }

    private const string GOOGLE_AUTH_PATTERN = '/Google OAuth|invalid_grant|UNAUTHENTICATED|\\[401\\]/i';

    private const string TOKEN_PATTERN = '/access token|OAuthException/i';

    private const int MAX_MESSAGE_LENGTH = 300;

    /**
     * @param  string|null  $platform  platform_deliveries.platform of the row (google_ads, meta, ga4, ...)
     */
    public static function fromResponseBody(?string $body, ?string $platform = null): string
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

        $authCode = self::authCode($body, $platform);
        if ($authCode !== null) {
            return $authCode;
        }

        if (preg_match('/\b([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)\b/', $body, $matches) === 1) {
            return $matches[1];
        }

        return self::UNKNOWN;
    }

    private static function authCode(string $body, ?string $platform): ?string
    {
        $google = preg_match(self::GOOGLE_AUTH_PATTERN, $body) === 1;
        $token = preg_match(self::TOKEN_PATTERN, $body) === 1;

        return match (true) {
            $platform === Platform::GoogleAds->value => $google || $token ? self::GOOGLE_ADS_AUTH : null,
            $platform === Platform::Meta->value => $token ? self::META_ACCESS_TOKEN : null,
            $platform !== null => $token ? self::ACCESS_TOKEN_INVALID : null,
            // Unknown platform: only the message itself can tell.
            $google => self::GOOGLE_ADS_AUTH,
            $token => self::META_ACCESS_TOKEN,
            default => null,
        };
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
