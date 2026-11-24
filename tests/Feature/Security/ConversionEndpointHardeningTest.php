<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->shop = User::factory()->create(['name' => 'hard.myshopify.com', 'tracking_secret' => 'secret']);
});

function hardPayload(array $overrides = []): array
{
    return ['shop_domain' => 'hard.myshopify.com', 'tracking_secret' => 'secret', 'event' => 'add_to_cart', ...$overrides];
}

it('only accepts known event types', function (string $event): void {
    $this->postJson('/api/conversions', hardPayload(['event' => $event]))->assertUnprocessable()->assertJsonValidationErrors(['event']);
})->with(['page_view', 'Purchase', 'purchase; DROP', str_repeat('a', 300)]);

it('accepts every TrackingEventType', function (): void {
    foreach (TrackingEventType::cases() as $type) {
        $payload = $type === TrackingEventType::Purchase ? ['value' => 1, 'currency' => 'CAD', 'transaction_id' => '1'] : [];
        $this->postJson('/api/conversions', hardPayload(['event' => $type->value, ...$payload]))->assertOk();
    }
});

it('rejects bodies larger than 16 KB with 413', function (): void {
    $this->postJson('/api/conversions', hardPayload(['fbp' => str_repeat('x', 17 * 1024)]))->assertStatus(413);

    expect(TrackingEvent::query()->count())->toBe(0);
});

it('bounds identifier lengths', function (): void {
    $this->postJson('/api/conversions', hardPayload(['gclid' => str_repeat('g', 600)]))->assertUnprocessable()->assertJsonValidationErrors(['gclid']);
});

it('throttles per IP', function (): void {
    config(['tracking.conversions_rate_limit.per_ip_per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/conversions', hardPayload())->assertOk();
    }
    $this->postJson('/api/conversions', hardPayload())->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->postJson('/api/conversions', hardPayload())->assertOk();
});

it('throttles per shop across IPs, including bad requests', function (): void {
    config(['tracking.conversions_rate_limit.per_shop_per_minute' => 2]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->postJson('/api/conversions', hardPayload(['tracking_secret' => 'wrong']))->assertUnauthorized();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->postJson('/api/conversions', hardPayload())->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->postJson('/api/conversions', hardPayload())->assertStatus(429);
});
