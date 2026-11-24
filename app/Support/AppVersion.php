<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deployed version for support emails: APP_VERSION / SENTRY_RELEASE when set, otherwise the
 * short git commit of the checkout (read from .git without running git), otherwise "unknown".
 */
final class AppVersion
{
    public static function current(): string
    {
        $configured = config('tracking.app_version');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return self::gitCommit() ?? 'unknown';
    }

    private static function gitCommit(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! is_file($head) || ! is_readable($head)) {
            return null;
        }

        $ref = trim((string) file_get_contents($head));
        if (str_starts_with($ref, 'ref: ')) {
            $path = base_path('.git/'.substr($ref, 5));
            $ref = is_file($path) ? trim((string) file_get_contents($path)) : '';
        }

        return preg_match('/^[0-9a-f]{40}$/', $ref) === 1 ? substr($ref, 0, 7) : null;
    }
}
