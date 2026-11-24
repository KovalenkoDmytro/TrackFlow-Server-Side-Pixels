<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\TrackingEventType;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

function ovIntegration(User $shop, Platform $platform): PlatformIntegration
{
    return PlatformIntegration::query()->firstOrCreate(
        ['user_id' => $shop->getKey(), 'platform' => $platform],
        ['active' => true, 'credentials' => json_encode(['customer_id' => '1234567890']), 'settings' => []],
    );
}

function ovDelivery(User $shop, PlatformIntegration $integration, TrackingEventType $type, string $status, string $occurredAt, ?string $body = null): void
{
    $event = TrackingEvent::factory()->forUser($shop)->forEvent($type)->occurredAt($occurredAt)->create();

    PlatformDelivery::query()->forceCreate([
        'tracking_event_id' => $event->getKey(),
        'platform_integration_id' => $integration->getKey(),
        'platform' => $integration->platform->value,
        'status' => $status,
        'attempts' => 1,
        'response_body' => $body,
        'created_at' => $occurredAt,
        'updated_at' => $occurredAt,
    ]);
}

describe('GET /api/overview', function (): void {
    beforeEach(function (): void {
        $this->travelTo(Carbon::parse('2026-11-24 18:00:00'));
    });

    afterEach(function (): void {
        $this->travelBack();
    });

    it('requires a session token', function (): void {
        $this->getJson('/api/overview')->assertUnauthorized();
    });

    it('rejects unsupported periods', function (): void {
        $shop = User::factory()->create();

        $this->withToken($this->shopifySessionToken($shop))->getJson('/api/overview?days=12')->assertUnprocessable();
    });

    it('returns a zero-filled daily series and an empty state flag when there is no data', function (): void {
        $shop = User::factory()->create(['pixel_enabled' => true]);

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/overview?days=7');

        $response->assertOk()
            ->assertJsonPath('has_data', false)
            ->assertJsonPath('period.days', 7)
            ->assertJsonPath('totals.delivery_rate', null)
            ->assertJsonPath('health.score', 100);
        expect($response->json('series.dates'))->toHaveCount(7)
            ->and($response->json('series.events'))->toBe([0, 0, 0, 0, 0, 0, 0]);
    });

    it('builds KPIs, delivery rates, health and grouped issues from stored deliveries', function (): void {
        $shop = User::factory()->create(['pixel_enabled' => true]);
        $ads = ovIntegration($shop, Platform::GoogleAds);
        $meta = ovIntegration($shop, Platform::Meta);
        $precedes = '{"codes":["CONVERSION_PRECEDES_EVENT"],"message":"The conversion precedes the event."}';

        ovDelivery($shop, $ads, TrackingEventType::Purchase, 'delivered', '2026-11-24 10:00:00');
        ovDelivery($shop, $ads, TrackingEventType::ViewItem, 'delivered', '2026-11-24 10:05:00');
        ovDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', '2026-11-24 10:10:00', $precedes);
        ovDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', '2026-11-23 09:00:00', $precedes);
        ovDelivery($shop, $ads, TrackingEventType::ViewItem, 'other_account', '2026-11-24 11:00:00', '{"codes":["INVALID_CUSTOMER_FOR_CLICK"],"customer_id":"1"}');
        ovDelivery($shop, $meta, TrackingEventType::Purchase, 'delivered', '2026-11-24 10:00:00');

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/overview?days=30');

        $response->assertOk()
            ->assertJsonPath('has_data', true)
            ->assertJsonPath('totals.events', 6)
            ->assertJsonPath('totals.purchases', 2)
            ->assertJsonPath('totals.failed', 2)
            ->assertJsonPath('platforms.google_ads.connected', true)
            ->assertJsonPath('platforms.google_ads.delivery_rate', 50)   // 2 of 4 eligible (other_account excluded)
            ->assertJsonPath('platforms.meta.delivery_rate', 100)
            ->assertJsonPath('platforms.tiktok.coming_soon', true)
            ->assertJsonPath('issues.0.platform', 'google_ads')
            ->assertJsonPath('issues.0.code', 'CONVERSION_PRECEDES_EVENT')
            ->assertJsonPath('issues.0.count', 2)
            ->assertJsonPath('issues.0.events.0.event', 'view_item');

        expect($response->json('series.dates'))->toHaveCount(30)
            ->and(last($response->json('series.events')))->toBe(5)
            ->and(last($response->json('series.failed')))->toBe(1)
            ->and($response->json('health.score'))->toBeLessThan(100)
            ->and($response->json('health.deductions.0.platform'))->toBe('google_ads');
    });

    it('keeps each issue on its own platform and scores Meta failures too', function (): void {
        $shop = User::factory()->create(['pixel_enabled' => true]);
        $ads = ovIntegration($shop, Platform::GoogleAds);
        $meta = ovIntegration($shop, Platform::Meta);
        $googleToken = 'Failed to obtain access token from Google OAuth [invalid_client].';
        $metaToken = 'Error validating access token: Session has expired on Tuesday.';

        ovDelivery($shop, $ads, TrackingEventType::ViewItem, 'failed', '2026-11-24 09:00:00', $googleToken);
        ovDelivery($shop, $ads, TrackingEventType::Purchase, 'failed', '2026-11-24 09:05:00', $googleToken);
        ovDelivery($shop, $ads, TrackingEventType::ViewItem, 'delivered', '2026-11-24 09:10:00');
        ovDelivery($shop, $meta, TrackingEventType::Purchase, 'failed', '2026-11-24 10:00:00', $metaToken);
        ovDelivery($shop, $meta, TrackingEventType::ViewItem, 'failed', '2026-11-24 10:05:00', $metaToken);
        ovDelivery($shop, $meta, TrackingEventType::ViewItem, 'delivered', '2026-11-24 10:10:00');

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/overview?days=7');
        $response->assertOk();

        $issues = collect($response->json('issues'))->keyBy('platform');
        expect($issues->keys()->sort()->values()->all())->toBe(['google_ads', 'meta'])
            ->and($issues['google_ads']['code'])->toBe('GOOGLE_ADS_AUTH')
            ->and($issues['meta']['code'])->toBe('META_ACCESS_TOKEN')
            ->and($issues['meta']['count'])->toBe(2)
            ->and(collect($issues['meta']['events'])->pluck('count', 'event')->sortKeys()->all())->toBe(['purchase' => 1, 'view_item' => 1])
            ->and($response->json('platforms.meta.failed'))->toBe(2)
            ->and(collect($response->json('health.deductions'))->pluck('platform')->sort()->values()->all())->toBe(['google_ads', 'meta']);
    });
});

describe('GET /api/analytics platform delivery rate', function (): void {
    it('adds delivery_rate per event and to totals, plus grouped issues', function (): void {
        $this->travelTo(Carbon::parse('2026-11-24 18:00:00'));
        $shop = User::factory()->create();
        $ads = ovIntegration($shop, Platform::GoogleAds);

        ovDelivery($shop, $ads, TrackingEventType::ViewItem, 'delivered', '2026-11-24 10:00:00');
        ovDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', '2026-11-24 10:10:00', '{"codes":["CONVERSION_PRECEDES_EVENT"],"message":"x"}');
        ovDelivery($shop, $ads, TrackingEventType::ViewItem, 'expired', '2026-11-24 10:20:00', '{"codes":["EXPIRED_EVENT"],"message":"old"}');

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/analytics?mode=single_day&date=2026-11-24&platform=google_ads');

        $response->assertOk()
            ->assertJsonPath('summary.totals.delivery_rate', 50)
            ->assertJsonPath('summary.issues.0.code', 'CONVERSION_PRECEDES_EVENT');
        $viewItem = collect($response->json('summary.delivery_stats'))->firstWhere('event', 'view_item');
        $purchase = collect($response->json('summary.delivery_stats'))->firstWhere('event', 'purchase');
        expect($viewItem['delivery_rate'])->toEqual(50)
            ->and($purchase['delivery_rate'])->toBeNull();
        $this->travelBack();
    });
});
