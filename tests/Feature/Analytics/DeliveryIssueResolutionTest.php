<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\TrackingEventType;
use App\Models\DeliveryIssueResolution;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

const PRECEDES = '{"codes":["CONVERSION_PRECEDES_EVENT"],"message":"The conversion precedes the event."}';
const GOOGLE_TOKEN = 'Failed to obtain access token from Google OAuth [invalid_client].';

function resIntegration(User $shop, Platform $platform, ?string $connectedAt = null): PlatformIntegration
{
    $integration = PlatformIntegration::query()->firstOrCreate(
        ['user_id' => $shop->getKey(), 'platform' => $platform],
        ['active' => true, 'credentials' => json_encode(['customer_id' => '1234567890']), 'settings' => []],
    );
    $integration->forceFill(['connected_at' => $connectedAt])->save();

    return $integration;
}

function resDelivery(User $shop, PlatformIntegration $integration, TrackingEventType $type, string $status, string $at, ?string $body = null): void
{
    $event = TrackingEvent::factory()->forUser($shop)->forEvent($type)->occurredAt($at)->create();

    PlatformDelivery::query()->forceCreate([
        'tracking_event_id' => $event->getKey(),
        'platform_integration_id' => $integration->getKey(),
        'platform' => $integration->platform->value,
        'status' => $status,
        'attempts' => 1,
        'response_body' => $body,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/** Shop with 1 delivered purchase and 3 View Item CONVERSION_PRECEDES_EVENT failures on Nov 24 morning. */
function resShopWithFailures(): array
{
    $shop = User::factory()->create(['pixel_enabled' => true]);
    $ads = resIntegration($shop, Platform::GoogleAds);
    resDelivery($shop, $ads, TrackingEventType::Purchase, 'delivered', '2026-11-24 09:00:00');
    foreach (['10:00', '10:05', '10:10'] as $time) {
        resDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', "2026-11-24 {$time}:00", PRECEDES);
    }

    return [$shop, $ads];
}

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-24 18:00:00')));
afterEach(fn () => $this->travelBack());

describe('POST/DELETE /api/delivery-issues/resolutions', function (): void {
    it('requires a session token', function (): void {
        $this->postJson('/api/delivery-issues/resolutions', ['platform' => 'google_ads', 'code' => 'X'])->assertUnauthorized();
        $this->deleteJson('/api/delivery-issues/resolutions?platform=google_ads&code=X')->assertUnauthorized();
    });

    it('validates platform and code', function (array $payload): void {
        $shop = User::factory()->create();

        $this->withToken($this->shopifySessionToken($shop))->postJson('/api/delivery-issues/resolutions', $payload)->assertUnprocessable();
    })->with([
        'missing code' => [['platform' => 'google_ads']],
        'unknown platform' => [['platform' => 'tiktok', 'code' => 'X']],
        'bad code' => [['platform' => 'meta', 'code' => 'DROP TABLE;']],
    ]);

    it('stores a resolution for the current shop only, moves it forward and undoes it', function (): void {
        $shop = User::factory()->create();
        $other = User::factory()->create();
        DeliveryIssueResolution::query()->create(['user_id' => $other->getKey(), 'platform' => 'google_ads', 'code' => 'CONVERSION_PRECEDES_EVENT', 'resolved_at' => now()->subDay()]);
        $token = $this->shopifySessionToken($shop);

        $this->withToken($token)->postJson('/api/delivery-issues/resolutions', ['platform' => 'google_ads', 'code' => 'CONVERSION_PRECEDES_EVENT'])
            ->assertCreated()
            ->assertJsonPath('code', 'CONVERSION_PRECEDES_EVENT')
            ->assertJsonPath('resolved_at', '2026-11-24T18:00:00+00:00');

        $this->travelTo(Carbon::parse('2026-11-24 19:00:00'));
        $token = $this->shopifySessionToken($shop); // session tokens are short-lived
        $this->withToken($token)->postJson('/api/delivery-issues/resolutions', ['platform' => 'google_ads', 'code' => 'CONVERSION_PRECEDES_EVENT'])->assertCreated();

        expect(DeliveryIssueResolution::query()->where('user_id', $shop->getKey())->count())->toBe(1)
            ->and(DeliveryIssueResolution::query()->where('user_id', $shop->getKey())->firstOrFail()->resolved_at->toDateTimeString())->toBe('2026-11-24 19:00:00');

        $this->withToken($token)->deleteJson('/api/delivery-issues/resolutions?platform=google_ads&code=CONVERSION_PRECEDES_EVENT')
            ->assertOk()
            ->assertJsonPath('removed', true);

        expect(DeliveryIssueResolution::query()->where('user_id', $shop->getKey())->exists())->toBeFalse()
            ->and(DeliveryIssueResolution::query()->where('user_id', $other->getKey())->exists())->toBeTrue();
    });
});

describe('resolved issues in the overview', function (): void {
    it('hides resolved failures from issue cards and the health score but keeps raw totals', function (): void {
        [$shop] = resShopWithFailures();
        $token = $this->shopifySessionToken($shop);

        $before = $this->withToken($token)->getJson('/api/overview?days=7')->assertOk();
        expect($before->json('issues'))->toHaveCount(1)
            ->and($before->json('health.score'))->toBeLessThan(100);

        $this->withToken($token)->postJson('/api/delivery-issues/resolutions', ['platform' => 'google_ads', 'code' => 'CONVERSION_PRECEDES_EVENT'])->assertCreated();

        $after = $this->withToken($token)->getJson('/api/overview?days=7')->assertOk();
        $after->assertJsonPath('issues', [])
            ->assertJsonPath('health.score', 100)
            ->assertJsonPath('health.deductions', [])
            ->assertJsonPath('totals.failed', 3)
            ->assertJsonPath('totals.resolved_failed', 3)
            ->assertJsonPath('platforms.google_ads.failed', 3)
            ->assertJsonPath('platforms.google_ads.resolved_failed', 3)
            ->assertJsonPath('platforms.google_ads.delivery_rate', 25);
    });

    it('brings the card back with only failures after resolved_at, and undo restores all', function (): void {
        [$shop, $ads] = resShopWithFailures();
        $token = $this->shopifySessionToken($shop);
        $this->withToken($token)->postJson('/api/delivery-issues/resolutions', ['platform' => 'google_ads', 'code' => 'CONVERSION_PRECEDES_EVENT'])->assertCreated();

        $this->travelTo(Carbon::parse('2026-11-24 19:00:00'));
        $token = $this->shopifySessionToken($shop); // session tokens are short-lived
        resDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', '2026-11-24 18:30:00', PRECEDES);

        $this->withToken($token)->getJson('/api/overview?days=7')->assertOk()
            ->assertJsonPath('issues.0.code', 'CONVERSION_PRECEDES_EVENT')
            ->assertJsonPath('issues.0.count', 1)
            ->assertJsonPath('issues.0.resolved_at', '2026-11-24T18:00:00+00:00')
            ->assertJsonPath('totals.resolved_failed', 3);

        $this->withToken($token)->deleteJson('/api/delivery-issues/resolutions?platform=google_ads&code=CONVERSION_PRECEDES_EVENT')->assertOk();

        $this->withToken($token)->getJson('/api/overview?days=7')->assertOk()
            ->assertJsonPath('issues.0.count', 4)
            ->assertJsonPath('issues.0.resolved_at', null)
            ->assertJsonPath('totals.resolved_failed', 0);
    });

    it('does not hide another code or another platform', function (): void {
        [$shop, $ads] = resShopWithFailures();
        $meta = resIntegration($shop, Platform::Meta);
        resDelivery($shop, $ads, TrackingEventType::Purchase, 'failed', '2026-11-24 11:00:00', '{"codes":["TOO_RECENT_EVENT"],"message":"x"}');
        resDelivery($shop, $meta, TrackingEventType::Purchase, 'failed', '2026-11-24 11:00:00', '{"codes":["CONVERSION_PRECEDES_EVENT"],"message":"x"}');
        $token = $this->shopifySessionToken($shop);

        $this->withToken($token)->postJson('/api/delivery-issues/resolutions', ['platform' => 'google_ads', 'code' => 'CONVERSION_PRECEDES_EVENT'])->assertCreated();

        $issues = collect($this->withToken($token)->getJson('/api/overview?days=7')->json('issues'))
            ->map(fn (array $i): string => $i['platform'].'|'.$i['code'])->sort()->values()->all();

        expect($issues)->toBe(['google_ads|TOO_RECENT_EVENT', 'meta|CONVERSION_PRECEDES_EVENT']);
    });

    it('also hides resolved failures on the platform analytics page', function (): void {
        [$shop] = resShopWithFailures();
        $token = $this->shopifySessionToken($shop);
        $this->withToken($token)->postJson('/api/delivery-issues/resolutions', ['platform' => 'google_ads', 'code' => 'CONVERSION_PRECEDES_EVENT'])->assertCreated();

        $this->withToken($token)->getJson('/api/analytics?mode=single_day&date=2026-11-24&platform=google_ads')->assertOk()
            ->assertJsonPath('summary.issues', [])
            ->assertJsonPath('summary.totals.failed', 3);
    });
});

describe('sign-in failures before a reconnect', function (): void {
    it('ignores auth failures from before connected_at and keeps newer ones and other codes', function (): void {
        $shop = User::factory()->create(['pixel_enabled' => true]);
        $ads = resIntegration($shop, Platform::GoogleAds, '2026-11-24 12:00:00');
        resDelivery($shop, $ads, TrackingEventType::ViewItem, 'failed', '2026-11-24 10:00:00', GOOGLE_TOKEN);
        resDelivery($shop, $ads, TrackingEventType::Purchase, 'failed', '2026-11-24 11:00:00', GOOGLE_TOKEN);
        resDelivery($shop, $ads, TrackingEventType::ViewItem, 'failed', '2026-11-24 13:00:00', GOOGLE_TOKEN);
        resDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', '2026-11-24 10:00:00', PRECEDES);

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/overview?days=7')->assertOk();
        $issues = collect($response->json('issues'))->keyBy('code');

        expect($issues['GOOGLE_ADS_AUTH']['count'])->toBe(1)
            ->and($issues['CONVERSION_PRECEDES_EVENT']['count'])->toBe(1)
            ->and($response->json('totals.resolved_failed'))->toBe(2)
            ->and($response->json('totals.failed'))->toBe(4);
    });

    it('counts every auth failure when connected_at is unknown', function (): void {
        $shop = User::factory()->create(['pixel_enabled' => true]);
        $ads = resIntegration($shop, Platform::GoogleAds);
        resDelivery($shop, $ads, TrackingEventType::ViewItem, 'failed', '2026-11-24 10:00:00', GOOGLE_TOKEN);
        resDelivery($shop, $ads, TrackingEventType::ViewItem, 'failed', '2026-11-24 13:00:00', GOOGLE_TOKEN);

        $this->withToken($this->shopifySessionToken($shop))->getJson('/api/overview?days=7')->assertOk()
            ->assertJsonPath('issues.0.code', 'GOOGLE_ADS_AUTH')
            ->assertJsonPath('issues.0.count', 2)
            ->assertJsonPath('totals.resolved_failed', 0);
    });
});
