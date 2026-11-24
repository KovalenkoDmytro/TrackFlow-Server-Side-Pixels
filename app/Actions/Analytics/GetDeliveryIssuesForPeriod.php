<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\TrackingEventType;
use App\Models\PlatformDelivery;
use App\Models\User;
use App\Support\Analytics\DeliveryErrorCode;
use App\Support\Analytics\HealthScore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Groups failed deliveries (status failed / partial_failure) by platform and error code
 * so the UI can show one actionable card per problem instead of a truncated last_error.
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
    public function handle(User $shop, CarbonImmutable $start, CarbonImmutable $end, array $platforms): array
    {
        if ($platforms === []) {
            return [];
        }

        $rows = PlatformDelivery::query()
            ->join('tracking_events', 'tracking_events.id', '=', 'platform_deliveries.tracking_event_id')
            ->where('tracking_events.user_id', '=', $shop->getKey())
            ->whereIn('platform_deliveries.platform', $platforms)
            ->whereIn('platform_deliveries.status', ['failed', 'partial_failure'])
            ->whereBetween('tracking_events.occurred_at', [$start, $end])
            ->orderByDesc('platform_deliveries.id')
            ->limit(self::MAX_ROWS)
            ->get([
                'platform_deliveries.platform as platform',
                'tracking_events.event as event',
                'platform_deliveries.response_body as response_body',
                'platform_deliveries.updated_at as last_at',
            ]);

        /** @var array<string, array{platform: string, code: string, count: int, impact: float, events: array<string, int>, message: string, last_seen_at: string|null}> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $platform = (string) $row->getAttribute('platform');
            $event = (string) $row->getAttribute('event');
            $body = $row->getAttribute('response_body');
            $body = is_string($body) ? $body : null;
            $code = DeliveryErrorCode::fromResponseBody($body);
            $key = $platform.'|'.$code;

            if (! isset($groups[$key])) {
                $lastAt = $row->getAttribute('last_at');
                $groups[$key] = [
                    'platform' => $platform,
                    'code' => $code,
                    'count' => 0,
                    'impact' => 0.0,
                    'events' => [],
                    // Rows are newest first, so the first row seen carries the latest message.
                    'message' => DeliveryErrorCode::message($body),
                    'last_seen_at' => $lastAt === null ? null : Carbon::parse($lastAt)->toIso8601String(),
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['impact'] += HealthScore::weight($event);
            $groups[$key]['events'][$event] = ($groups[$key]['events'][$event] ?? 0) + 1;
        }

        $issues = array_map(function (array $group): array {
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

            return $group;
        }, array_values($groups));

        usort($issues, fn (array $a, array $b): int => [$b['impact'], $b['count']] <=> [$a['impact'], $a['count']]);

        return $issues;
    }
}
