<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\TrackingEventType;
use App\Models\PlatformDelivery;
use App\Models\SupportReport;
use App\Models\User;
use App\Support\Analytics\DeliveryErrorCode;
use App\Support\Analytics\FailureCutoffs;
use App\Support\Analytics\HealthScore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Groups failed deliveries (status failed / partial_failure) by platform and error code
 * so the UI can show one actionable card per problem instead of a truncated last_error.
 *
 * Failures the merchant marked resolved, and sign-in failures from before the latest
 * reconnect, are left out (see FailureCutoffs); ignoredFailures() counts them per event
 * so the health score can leave them out too.
 *
 * Read-only: this never changes delivery rows. other_account and expired rows are not
 * failures and are excluded.
 *
 * @phpstan-type DeliveryIssue array{
 *     platform: string,
 *     code: string,
 *     count: int,
 *     impact: float,
 *     events: list<array{event: string, label: string, count: int}>,
 *     message: string,
 *     last_seen_at: string|null,
 *     resolved_at: string|null,
 *     support: array{primary: bool, reported_at: string|null},
 * }
 */
final class GetDeliveryIssuesForPeriod
{
    use AsObject;

    /** Most recent failed rows inspected per request; enough to group, cheap to read. */
    private const int MAX_ROWS = 5000;

    /**
     * @param  list<string>  $platforms
     * @return list<DeliveryIssue>
     */
    public function handle(User $shop, CarbonImmutable $start, CarbonImmutable $end, array $platforms, ?FailureCutoffs $cutoffs = null): array
    {
        if ($platforms === []) {
            return [];
        }

        $cutoffs ??= FailureCutoffs::forShop($shop);

        $rows = $this->failedRows($shop, $start, $end, $platforms)
            ->orderByDesc('platform_deliveries.id')
            ->limit(self::MAX_ROWS)
            ->get([
                'platform_deliveries.platform as platform',
                'tracking_events.event as event',
                'platform_deliveries.response_body as response_body',
                'platform_deliveries.updated_at as last_at',
            ]);

        /** @var array<string, array{platform: string, code: string, count: int, impact: float, events: array<string, int>, message: string, last_seen_at: string|null, resolved_at: string|null}> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $platform = (string) $row->getAttribute('platform');
            $event = (string) $row->getAttribute('event');
            $body = $row->getAttribute('response_body');
            $body = is_string($body) ? $body : null;
            $code = DeliveryErrorCode::fromResponseBody($body, $platform);
            $lastAt = $row->getAttribute('last_at');

            if ($cutoffs->ignores($platform, $code, $lastAt)) {
                continue;
            }

            $key = $platform.'|'.$code;

            if (! isset($groups[$key])) {
                $resolvedAt = $cutoffs->resolvedAt($platform, $code);
                $groups[$key] = [
                    'platform' => $platform,
                    'code' => $code,
                    'count' => 0,
                    'impact' => 0.0,
                    'events' => [],
                    // Rows are newest first, so the first row seen carries the latest message.
                    'message' => DeliveryErrorCode::message($body),
                    'last_seen_at' => $lastAt === null ? null : Carbon::parse($lastAt)->toIso8601String(),
                    // Set when the issue came back after the merchant marked it resolved.
                    'resolved_at' => $resolvedAt?->toIso8601String(),
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['impact'] += HealthScore::weight($event);
            $groups[$key]['events'][$event] = ($groups[$key]['events'][$event] ?? 0) + 1;
        }

        $reported = $this->openReports($shop);

        $issues = array_map(function (array $group) use ($reported): array {
            arsort($group['events']);
            $events = [];
            foreach ($group['events'] as $event => $count) {
                $events[] = [
                    'event' => (string) $event,
                    'label' => TrackingEventType::tryFrom((string) $event)?->label() ?? (string) $event,
                    'count' => $count,
                ];
            }
            $group['events'] = $events;
            $group['impact'] = round($group['impact'], 1);
            // primary: the merchant cannot fix this, so "Send to support" is the main action.
            // reported_at: an open support report exists (the card shows a calm state).
            $group['support'] = [
                'primary' => DeliveryErrorCode::needsSupport($group['code']),
                'reported_at' => $reported[$group['platform'].'|'.$group['code']] ?? null,
            ];

            return $group;
        }, array_values($groups));

        usort($issues, fn (array $a, array $b): int => [$b['impact'], $b['count']] <=> [$a['impact'], $a['count']]);

        return $issues;
    }

    /**
     * Failures left out by the cutoffs, per platform and event, for the health score.
     * Only rows at or before a platform's latest cutoff are read and classified.
     *
     * @param  list<string>  $platforms
     * @return array<string, array<string, int>>
     */
    public function ignoredFailures(User $shop, CarbonImmutable $start, CarbonImmutable $end, array $platforms, FailureCutoffs $cutoffs): array
    {
        $ignored = [];

        foreach ($platforms as $platform) {
            $latest = $cutoffs->latestCutoff($platform);

            if ($latest === null) {
                continue;
            }

            $rows = $this->failedRows($shop, $start, $end, [$platform])
                ->where('platform_deliveries.updated_at', '<=', $latest)
                ->select([
                    'tracking_events.event as event',
                    'platform_deliveries.response_body as response_body',
                    'platform_deliveries.updated_at as last_at',
                ])
                ->toBase()
                ->cursor();

            foreach ($rows as $row) {
                $code = DeliveryErrorCode::fromResponseBody(is_string($row->response_body) ? $row->response_body : null, $platform);

                if ($cutoffs->ignores($platform, $code, $row->last_at)) {
                    $ignored[$platform][(string) $row->event] = ($ignored[$platform][(string) $row->event] ?? 0) + 1;
                }
            }
        }

        return $ignored;
    }

    /**
     * @param  list<string>  $platforms
     * @return Builder<PlatformDelivery>
     */
    private function failedRows(User $shop, CarbonImmutable $start, CarbonImmutable $end, array $platforms): Builder
    {
        return PlatformDelivery::query()
            ->join('tracking_events', 'tracking_events.id', '=', 'platform_deliveries.tracking_event_id')
            ->where('tracking_events.user_id', '=', $shop->getKey())
            ->whereIn('platform_deliveries.platform', $platforms)
            ->whereIn('platform_deliveries.status', ['failed', 'partial_failure'])
            ->whereBetween('tracking_events.occurred_at', [$start, $end]);
    }

    /**
     * Open support reports of the shop, keyed by platform|code, with the reported time.
     *
     * @return array<string, string>
     */
    private function openReports(User $shop): array
    {
        $out = [];
        foreach (SupportReport::query()->open()->where('user_id', $shop->getKey())->get(['platform', 'code', 'reported_at']) as $report) {
            $out[$report->platform.'|'.$report->code] = $report->reported_at->toIso8601String();
        }

        return $out;
    }
}
