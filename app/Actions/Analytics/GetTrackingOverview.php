<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\Platform;
use App\Models\PlatformDelivery;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Support\Analytics\DeliveryRate;
use App\Support\Analytics\FailureCutoffs;
use App\Support\Analytics\HealthScore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Builds the home page overview: health score, headline KPIs, daily series for
 * sparklines (from real stored events, zero-filled), per-platform delivery and
 * grouped delivery issues. Read-only.
 *
 * Resolved failures (and sign-in failures from before a reconnect) are left out of the
 * issue cards and the health score only. Raw delivered / failed totals, delivery rates and
 * the daily series stay unchanged: those uploads really were rejected by the platform.
 * resolved_failed reports how many of the failed uploads are no longer counted.
 */
final class GetTrackingOverview
{
    use AsObject;

    private const array DELIVERY_PLATFORMS = [Platform::GoogleAds->value, Platform::Meta->value];

    public function __construct(
        private readonly GetPlatformDeliveryStatsForPeriod $deliveryStats,
        private readonly GetDeliveryIssuesForPeriod $deliveryIssues,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $shop, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): array
    {
        $connected = $shop->platformIntegrations()
            ->where('active', true)
            ->get()
            ->map(fn ($integration): string => $integration->platform->value)
            ->values()
            ->all();

        $dates = [];
        for ($day = $start->startOfDay(); $day->lte($end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        $eventsByDay = TrackingEvent::query()
            ->where('user_id', '=', $shop->getKey())
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw('DATE(occurred_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN event = 'purchase' THEN 1 ELSE 0 END) as purchases")
            ->groupByRaw('DATE(occurred_at)')
            ->get()
            ->keyBy(fn ($row): string => substr((string) $row->getAttribute('day'), 0, 10));

        $deliveriesByDay = PlatformDelivery::query()
            ->join('tracking_events', 'tracking_events.id', '=', 'platform_deliveries.tracking_event_id')
            ->where('tracking_events.user_id', '=', $shop->getKey())
            ->whereIn('platform_deliveries.platform', self::DELIVERY_PLATFORMS)
            ->whereBetween('tracking_events.occurred_at', [$start, $end])
            ->selectRaw('DATE(tracking_events.occurred_at) as day')
            ->selectRaw('COUNT(*) as attempted')
            ->selectRaw("SUM(CASE WHEN platform_deliveries.status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN platform_deliveries.status IN ('failed', 'partial_failure') THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN platform_deliveries.status = 'queued' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN platform_deliveries.status = 'other_account' THEN 1 ELSE 0 END) as other_account")
            ->selectRaw("SUM(CASE WHEN platform_deliveries.status = 'expired' THEN 1 ELSE 0 END) as expired")
            ->groupByRaw('DATE(tracking_events.occurred_at)')
            ->get()
            ->keyBy(fn ($row): string => substr((string) $row->getAttribute('day'), 0, 10));

        $series = ['dates' => $dates, 'events' => [], 'purchases' => [], 'failed' => [], 'delivery_rate' => []];
        foreach ($dates as $date) {
            $e = $eventsByDay->get($date);
            $d = $deliveriesByDay->get($date);
            $series['events'][] = (int) ($e?->getAttribute('total') ?? 0);
            $series['purchases'][] = (int) ($e?->getAttribute('purchases') ?? 0);
            $series['failed'][] = (int) ($d?->getAttribute('failed') ?? 0);
            $series['delivery_rate'][] = $d === null ? null : DeliveryRate::percent(
                (int) $d->getAttribute('delivered'),
                (int) $d->getAttribute('attempted'),
                (int) $d->getAttribute('other_account'),
                (int) $d->getAttribute('expired'),
                (int) $d->getAttribute('pending'),
            );
        }

        $cutoffs = FailureCutoffs::forShop($shop);
        $ignored = $this->deliveryIssues->ignoredFailures($shop, $start, $end, self::DELIVERY_PLATFORMS, $cutoffs);

        $platforms = [];
        $scoredStats = [];
        $sum = ['attempted' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'other_account' => 0, 'expired' => 0];
        $resolvedFailedTotal = 0;

        foreach (self::DELIVERY_PLATFORMS as $platform) {
            $stats = $this->deliveryStats->handle($shop, $start, $end, $platform);
            $totals = ['attempted' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'other_account' => 0, 'expired' => 0];
            foreach ($stats as $stat) {
                foreach (array_keys($totals) as $key) {
                    $totals[$key] += (int) $stat[$key];
                }
            }
            foreach (array_keys($sum) as $key) {
                $sum[$key] += $totals[$key];
            }

            $isConnected = in_array($platform, $connected, true);
            if ($isConnected) {
                $scoredStats[$platform] = $this->withoutIgnored($stats, $ignored[$platform] ?? []);
            }

            $resolvedFailed = array_sum($ignored[$platform] ?? []);
            $resolvedFailedTotal += $resolvedFailed;

            $platforms[$platform] = [
                'connected' => $isConnected,
                ...$totals,
                'resolved_failed' => $resolvedFailed,
                'delivery_rate' => DeliveryRate::forStat($totals),
            ];
        }

        $platforms[Platform::GoogleAnalytics4->value] = [
            'connected' => in_array(Platform::GoogleAnalytics4->value, $connected, true),
            'events' => TrackingEvent::query()
                ->where('user_id', '=', $shop->getKey())
                ->whereBetween('occurred_at', [$start, $end])
                ->whereNotNull('ga_client_id')
                ->count(),
        ];
        $platforms[Platform::TikTok->value] = ['connected' => false, 'coming_soon' => true];

        $totalEvents = array_sum($series['events']);
        $lastEventAt = TrackingEvent::query()->where('user_id', '=', $shop->getKey())->max('occurred_at');
        $hasRecentEvents = $lastEventAt !== null && Carbon::parse($lastEventAt)->gte($now->subDay());
        $pixelEnabled = (bool) $shop->pixel_enabled;

        return [
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => count($dates)],
            'pixel_enabled' => $pixelEnabled,
            'has_data' => $totalEvents > 0,
            'last_event_at' => $lastEventAt === null ? null : Carbon::parse($lastEventAt)->toIso8601String(),
            'totals' => [
                'events' => $totalEvents,
                'purchases' => array_sum($series['purchases']),
                'failed' => $sum['failed'],
                'resolved_failed' => $resolvedFailedTotal,
                'attempted' => $sum['attempted'],
                'delivered' => $sum['delivered'],
                'eligible' => DeliveryRate::eligibleForStat($sum),
                'delivery_rate' => DeliveryRate::forStat($sum),
            ],
            'series' => $series,
            'platforms' => $platforms,
            'health' => HealthScore::calculate($pixelEnabled, $totalEvents > 0, $hasRecentEvents, $scoredStats),
            'issues' => $this->deliveryIssues->handle($shop, $start, $end, self::DELIVERY_PLATFORMS, $cutoffs),
        ];
    }

    /**
     * Stats for the health score with ignored failures removed entirely (not failed and not
     * eligible), so a resolved problem neither lowers the score nor dilutes newer failures.
     *
     * @param  list<array<string, mixed>>  $stats
     * @param  array<string, int>  $ignored  per event
     * @return list<array<string, mixed>>
     */
    private function withoutIgnored(array $stats, array $ignored): array
    {
        if ($ignored === []) {
            return $stats;
        }

        return array_map(function (array $stat) use ($ignored): array {
            $n = min((int) $stat['failed'], $ignored[$stat['event']] ?? 0);
            $stat['failed'] = (int) $stat['failed'] - $n;
            $stat['attempted'] = (int) $stat['attempted'] - $n;

            return $stat;
        }, $stats);
    }
}
