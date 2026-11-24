<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Masks credentials in free text: URL query / form parameters (GA4 api_secret, access_token, ...),
 * JSON fields, Authorization headers and well-known token formats (Shopify shpat_/shpua_/...,
 * Meta EAA..., Google ya29-prefixed access and 1// refresh tokens). Used for stored error messages,
 * Sentry and every log record (RedactSecretsInLogs).
 */
final class SecretRedactor
{
    public const REDACTED = '[redacted]';

    /** Context / array keys whose string values are always masked. */
    public const SENSITIVE_KEY = '/token|secret|password|passwd|authorization|api[_-]?key|credential|cookie|signature/i';

    private const PATTERNS = [
        // ?access_token=... &api_secret=... and form bodies: refresh_token=...
        '/((?:^|[?&\s,;(])[A-Za-z0-9_.\-]*(?:token|secret|key|password|signature)[A-Za-z0-9_.\-]*=)[^&\s"\'<>),;]*/i' => '$1'.self::REDACTED,
        // "access_token":"...", 'client_secret' => '...'
        '/(["\']?[A-Za-z0-9_.\-]*(?:token|secret|password|api_?key|signature)[A-Za-z0-9_.\-]*["\']?\s*(?::|=>)\s*["\'])[^"\']*(["\'])/i' => '$1'.self::REDACTED.'$2',
        // Authorization: Bearer xxx / Basic xxx / X-Shopify-Access-Token: xxx
        '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 '.self::REDACTED,
        '/(X-Shopify-Access-Token["\']?\s*[:=]\s*["\']?)[^\s"\',;]+/i' => '$1'.self::REDACTED,
        // Shopify tokens and secrets.
        '/\bshp(?:at|ua|ca|pa|ss)_[A-Za-z0-9]+/' => self::REDACTED,
        // Meta access tokens.
        '/\bEAA[A-Za-z0-9]{20,}/' => self::REDACTED,
        // Google OAuth access / refresh tokens.
        '/\bya29\.[A-Za-z0-9\-_.]+/' => self::REDACTED,
        '/\b1\/\/[A-Za-z0-9\-_]{20,}/' => self::REDACTED,
    ];

    public static function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /** Redacted and bounded so it always fits a varchar column of $limit characters. */
    public static function bounded(string $text, int $limit = 255): string
    {
        return Str::limit(self::redact($text), $limit - 3, '...', true);
    }

    /**
     * Recursively masks an array: values under sensitive keys are replaced, other strings redacted.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redactArray(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1 && (is_string($value) || is_numeric($value))) {
                $data[$key] = $value === '' ? '' : self::REDACTED;
            } elseif (is_string($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $data[$key] = self::redactArray($value, $depth + 1);
            }
        }

        return $data;
    }
}
