<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\Platform;
use App\Enums\TrackingEventType;

/**
 * Tracking health score (0–100) shown on the app home page.
 *
 * Start at 100 and subtract:
 *   - 40 when the web pixel is disabled
 *   - 20 when the pixel is enabled, the period has data, but no event arrived in the last 24 hours
 *   - per ad platform (Google Ads, Meta; GA4 is excluded because it reports no delivery outcome):
 *       weighted failure rate = Σ(weight(event) × failed) / Σ eligible × 100
 *       penalty = min(30, max(0, weighted failure rate − 1) × 3)
 *     where weight is 1.0 for Purchase, 0.5 for cart/checkout steps and 0.2 for browse events.
 *     The first 1 point of weighted failure rate is tolerated.
 *
 * Status: 90+ healthy, 70–89 attention, below 70 critical.
 */
final class HealthScore
{
    public const float PIXEL_DISABLED_PENALTY = 40.0;

    public const float NO_RECENT_EVENTS_PENALTY = 20.0;

    public const float PLATFORM_PENALTY_CAP = 30.0;

    public const float TOLERATED_FAILURE_POINTS = 1.0;

    public const float POINTS_PER_FAILURE_POINT = 3.0;

    /** Platforms that count toward the score. GA4 has no delivery outcome and is excluded. */
    public const array SCORED_PLATFORMS = [Platform::GoogleAds->value, Platform::Meta->value];

    public static function weight(string $event): float
    {
        return match (TrackingEventType::tryFrom($event)) {
            TrackingEventType::Purchase => 1.0,
            TrackingEventType::BeginCheckout,
            TrackingEventType::AddPaymentInfo,
            TrackingEventType::AddShippingInfo,
            TrackingEventType::AddToCart => 0.5,
            default => 0.2,
        };
    }

    /**
     * Weighted failure rate in percentage points (0–100+), or null when nothing is eligible.
     *
     * @param  list<array{event: string, attempted: int, delivered: int, failed: int, pending?: int, other_account?: int, expired?: int}>  $stats
     */
    public static function weightedFailureRate(array $stats): ?float
    {
        $eligible = 0;
        $weightedFailed = 0.0;

        foreach ($stats as $stat) {
            $eligible += DeliveryRate::eligibleForStat($stat);
            $weightedFailed += self::weight($stat['event']) * (int) $stat['failed'];
        }

        if ($eligible === 0) {
            return null;
        }

        return $weightedFailed / $eligible * 100;
    }

    /**
     * @param  list<array{event: string, attempted: int, delivered: int, failed: int, pending?: int, other_account?: int, expired?: int}>  $stats
     */
    public static function platformPenalty(array $stats): float
    {
        $rate = self::weightedFailureRate($stats);

        if ($rate === null) {
            return 0.0;
        }

        $penalty = max(0.0, $rate - self::TOLERATED_FAILURE_POINTS) * self::POINTS_PER_FAILURE_POINT;

        return round(min(self::PLATFORM_PENALTY_CAP, $penalty), 1);
    }

    public static function status(int $score): string
    {
        return match (true) {
            $score >= 90 => 'healthy',
            $score >= 70 => 'attention',
            default => 'critical',
        };
    }

    /**
     * @param  array<string, list<array{event: string, attempted: int, delivered: int, failed: int, pending?: int, other_account?: int, expired?: int}>>  $platformStats  keyed by platform value; only connected platforms
     * @return array{score: int, status: string, deductions: list<array{key: string, platform: string|null, points: float, weighted_failure_rate: float|null}>}
     */
    public static function calculate(bool $pixelEnabled, bool $hasData, bool $hasRecentEvents, array $platformStats): array
    {
        $deductions = [];

        if (! $pixelEnabled) {
            $deductions[] = ['key' => 'pixel_disabled', 'platform' => null, 'points' => self::PIXEL_DISABLED_PENALTY, 'weighted_failure_rate' => null];
        } elseif ($hasData && ! $hasRecentEvents) {
            $deductions[] = ['key' => 'no_recent_events', 'platform' => null, 'points' => self::NO_RECENT_EVENTS_PENALTY, 'weighted_failure_rate' => null];
        }

        foreach ($platformStats as $platform => $stats) {
            if (! in_array($platform, self::SCORED_PLATFORMS, true)) {
                continue;
            }

            $points = self::platformPenalty($stats);

            if ($points > 0) {
                $rate = self::weightedFailureRate($stats);
                $deductions[] = [
                    'key' => 'delivery_failures',
                    'platform' => $platform,
                    'points' => $points,
                    'weighted_failure_rate' => $rate === null ? null : round($rate, 1),
                ];
            }
        }

        $total = array_sum(array_column($deductions, 'points'));
        $score = (int) max(0, min(100, round(100 - $total)));

        return ['score' => $score, 'status' => self::status($score), 'deductions' => $deductions];
    }
}
