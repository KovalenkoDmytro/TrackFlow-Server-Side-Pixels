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

    /**
     * Codes with a clear action the merchant can take (reconnect, fix the account or pixel, accept
     * terms, ...). Their cards keep that action as primary with a secondary "Contact support" link.
     * Every other code (code-side Google/Meta rejections, unknown errors) gets "Send to support"
     * as the primary action, because the merchant cannot fix it.
     */
    public const array MERCHANT_FIXABLE_CODES = [
        self::GOOGLE_ADS_AUTH,
        self::META_ACCESS_TOKEN,
        self::ACCESS_TOKEN_INVALID,
        'UNAUTHORIZED_CUSTOMER',
        'USER_PERMISSION_DENIED',
        'INVALID_CONVERSION_ACTION',
        'CUSTOMER_NOT_ACCEPTED_CUSTOMER_DATA_TERMS',
        'EVENT_NOT_FOUND',
        'INVALID_CUSTOMER_FOR_CLICK',
        'meta_no_events_received',
    ];

    public static function isMerchantFixable(string $code): bool
    {
        return in_array($code, self::MERCHANT_FIXABLE_CODES, true);
    }

    /** Whether the issue card shows "Send to support" as its primary action. */
    public static function needsSupport(string $code): bool
    {
        return ! self::isMerchantFixable($code);
    }

    /** Short human labels for support emails; the app UI has fuller guides (errorCatalog.ts). */
    private const array LABELS = [
        'CONVERSION_PRECEDES_EVENT' => 'Event time is earlier than the ad click',
        'LATER_THAN_MAXIMUM_DATE' => 'Event time is later than Google allows (in the future)',
        'TOO_RECENT_EVENT' => 'The ad click is too recent',
        'EXPIRED_EVENT' => 'The ad click is older than the conversion window',
        'EVENT_NOT_FOUND' => 'Google could not find the ad click',
        'UNPARSEABLE_GCLID' => 'The click ID could not be read',
        'INVALID_CONVERSION_ACTION' => 'The conversion action is missing or disabled',
        'CLICK_CONVERSION_ALREADY_EXISTS' => 'Google already has this conversion',
        'ORDER_ID_ALREADY_IN_USE' => 'Order ID already used',
        self::GOOGLE_ADS_AUTH => 'Google Ads sign-in could not be refreshed',
        self::META_ACCESS_TOKEN => 'Meta access token is invalid or expired',
        self::ACCESS_TOKEN_INVALID => 'Access token is invalid or expired',
        self::UNKNOWN => 'Unknown error',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? 'Platform rejected events ('.$code.')';
    }

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
