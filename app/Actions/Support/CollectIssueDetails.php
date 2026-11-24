<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Enums\TrackingEventType;
use App\Models\PlatformDelivery;
use App\Models\User;
use App\Support\Analytics\DeliveryErrorCode;
use App\Support\Analytics\FailureCutoffs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Snapshot of one open delivery issue (platform + error code) for a support report:
 * count, first/last failure, per-event breakdown and the newest sample failures.
 * Uses the same rules as the issue cards (resolved failures are left out). Read-only.
 */
final class CollectIssueDetails
{
    use AsObject;

    /** Failed rows of the platform scanned, newest first. */
    private const int MAX_ROWS = 20000;

    private const int SAMPLES = 5;

    private const int MAX_BODY_LENGTH = 2000;

    /**
     * @return array{count: int, first_failed_at: string|null, last_failed_at: string|null, events: list<array{event: string, label: string, count: int}>, samples: list<array<string, mixed>>}
     */
    public function handle(User $shop, string $platform, string $code, CarbonImmutable $start, CarbonImmutable $end, ?FailureCutoffs $cutoffs = null): array
    {
        $cutoffs ??= FailureCutoffs::forShop($shop);
        $count = 0;
        $events = [];
        $samples = [];
        $first = null;
        $last = null;

        $rows = PlatformDelivery::query()
            ->join('tracking_events', 'tracking_events.id', '=', 'platform_deliveries.tracking_event_id')
            ->where('tracking_events.user_id', '=', $shop->getKey())
            ->where('platform_deliveries.platform', $platform)
            ->whereIn('platform_deliveries.status', ['failed', 'partial_failure'])
            ->whereBetween('tracking_events.occurred_at', [$start, $end])
            ->orderByDesc('platform_deliveries.id')
            ->limit(self::MAX_ROWS)
            ->select([
                'platform_deliveries.id as delivery_id',
                'platform_deliveries.status as status',
                'platform_deliveries.attempts as attempts',
                'platform_deliveries.response_code as response_code',
                'platform_deliveries.response_body as response_body',
                'platform_deliveries.sent_at as sent_at',
                'platform_deliveries.updated_at as last_at',
                'tracking_events.id as tracking_event_id',
                'tracking_events.event as event',
                'tracking_events.occurred_at as occurred_at',
            ])
            ->toBase()
            ->cursor();

        foreach ($rows as $row) {
            $body = is_string($row->response_body) ? $row->response_body : null;

            if (DeliveryErrorCode::fromResponseBody($body, $platform) !== $code || $cutoffs->ignores($platform, $code, $row->last_at)) {
                continue;
            }

            $count++;
            $events[(string) $row->event] = ($events[(string) $row->event] ?? 0) + 1;
            $at = $row->last_at === null ? null : Carbon::parse($row->last_at);
            if ($at !== null) {
                $last = $last === null || $at->gt($last) ? $at : $last;
                $first = $first === null || $at->lt($first) ? $at : $first;
            }

            if (count($samples) < self::SAMPLES) {
                $samples[] = [
                    'delivery_id' => (int) $row->delivery_id,
                    'tracking_event_id' => (int) $row->tracking_event_id,
                    'event' => (string) $row->event,
                    'status' => (string) $row->status,
                    'attempts' => (int) $row->attempts,
                    'occurred_at' => $this->iso($row->occurred_at),
                    'uploaded_at' => $this->iso($row->sent_at ?? $row->last_at),
                    'response_code' => $row->response_code === null ? null : (int) $row->response_code,
                    'response_body' => $body === null ? null : mb_substr($body, 0, self::MAX_BODY_LENGTH),
                ];
            }
        }

        arsort($events);
        $breakdown = [];
        foreach ($events as $event => $n) {
            $breakdown[] = [
                'event' => (string) $event,
                'label' => TrackingEventType::tryFrom((string) $event)?->label() ?? (string) $event,
                'count' => $n,
            ];
        }

        return [
            'count' => $count,
            'first_failed_at' => $first?->toIso8601String(),
            'last_failed_at' => $last?->toIso8601String(),
            'events' => $breakdown,
            'samples' => $samples,
        ];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}
