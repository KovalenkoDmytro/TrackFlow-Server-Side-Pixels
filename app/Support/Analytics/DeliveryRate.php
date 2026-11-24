<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Delivery rate = accepted / eligible uploads, as a percentage.
 *
 * Eligible excludes rows that are not delivery outcomes:
 *   - other_account: the Google Ads click belongs to an account that is not connected (not a failure)
 *   - expired:       the click is older than the conversion window (not a failure)
 *   - pending:       still queued, no outcome yet
 *
 * Returns null when nothing is eligible, so the UI can show "—" instead of a misleading 0% or 100%.
 */
final class DeliveryRate
{
    public static function eligible(int $attempted, int $otherAccount = 0, int $expired = 0, int $pending = 0): int
    {
        return max(0, $attempted - $otherAccount - $expired - $pending);
    }

    public static function percent(int $delivered, int $attempted, int $otherAccount = 0, int $expired = 0, int $pending = 0): ?float
    {
        $eligible = self::eligible($attempted, $otherAccount, $expired, $pending);

        if ($eligible === 0) {
            return null;
        }

        return round(min(100.0, max(0.0, $delivered / $eligible * 100)), 1);
    }

    /**
     * @param  array{attempted: int, delivered: int, other_account?: int, expired?: int, pending?: int}  $stat
     */
    public static function forStat(array $stat): ?float
    {
        return self::percent(
            (int) $stat['delivered'],
            (int) $stat['attempted'],
            (int) ($stat['other_account'] ?? 0),
            (int) ($stat['expired'] ?? 0),
            (int) ($stat['pending'] ?? 0),
        );
    }

    /**
     * @param  array{attempted: int, other_account?: int, expired?: int, pending?: int}  $stat
     */
    public static function eligibleForStat(array $stat): int
    {
        return self::eligible(
            (int) $stat['attempted'],
            (int) ($stat['other_account'] ?? 0),
            (int) ($stat['expired'] ?? 0),
            (int) ($stat['pending'] ?? 0),
        );
    }
}
