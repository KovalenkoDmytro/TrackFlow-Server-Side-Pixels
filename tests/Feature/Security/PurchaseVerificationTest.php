<?php

declare(strict_types=1);

use App\Actions\Tracking\ProcessTrackingEvent;
use App\Actions\Tracking\VerifyPurchase;
use App\Contracts\ShopifyOrderLookup;
use App\Data\ShopifyOrderResult;
use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Decorators\JobDecorator;

/** In-memory Shopify orders for the tests. */
final class FakeOrderLookup implements ShopifyOrderLookup
{
    /** @var array<int, ShopifyOrderResult> */
    public array $orders = [];

    /** @var list<ShopifyOrderResult> */
    public array $queue = [];

    public bool $scope = true;

    public int $calls = 0;

    public function canReadOrders(User $shop): bool
    {
        return $this->scope;
    }

    public function find(User $shop, int $orderId): ShopifyOrderResult
    {
        $this->calls++;

        return array_shift($this->queue) ?? $this->orders[$orderId] ?? ShopifyOrderResult::notFound();
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 18:00:00'));
    config(['tracking.purchase_verification.mode' => 'auto']);
    $this->orders = new FakeOrderLookup;
    $this->app->instance(ShopifyOrderLookup::class, $this->orders);

    $this->shop = User::factory()->create(['tracking_secret' => 'secret']);
    $integration = PlatformIntegration::query()->create([
        'user_id' => $this->shop->getKey(),
        'platform' => Platform::Meta,
        'active' => true,
        'credentials' => json_encode(['pixel_id' => '123', 'access_token' => 'tok']),
        'settings' => [],
    ]);
    ConversionActionMapping::query()->create(['platform_integration_id' => $integration->getKey(), 'event' => 'purchase', 'external_action_id' => 'Purchase', 'active' => true]);
    Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'T'], 200)]);
});
afterEach(fn () => $this->travelBack());

function purchase(array $overrides = []): array
{
    return [
        'shop_domain' => test()->shop->name,
        'tracking_secret' => 'secret',
        'event' => 'purchase',
        'value' => 999999,
        'currency' => 'USD',
        'transaction_id' => 'gid://shopify/OrderIdentity/5551234',
        'idempotency_key' => 'evt-'.uniqid(),
        'fbp' => 'fb.1.1.2',
        ...$overrides,
    ];
}

function realOrder(int $id = 5551234, float $value = 42.5, string $currency = 'CAD', string $createdAt = '2026-11-24 17:59:00'): ShopifyOrderResult
{
    return ShopifyOrderResult::found($id, $value, $currency, CarbonImmutable::parse($createdAt));
}

it('parses Shopify order ids from the pixel transaction id', function (?string $input, ?int $expected): void {
    expect(VerifyPurchase::orderId($input))->toBe($expected);
})->with([
    ['5551234', 5551234],
    ['gid://shopify/Order/5551234', 5551234],
    ['gid://shopify/OrderIdentity/5551234', 5551234],
    ['c1-abcdef0123456789', null],
    ['gid://shopify/Customer/1', null],
    ['0', null],
    [null, null],
]);

it('forwards a verified purchase with value and currency from Shopify, not the client', function (): void {
    $this->orders->orders[5551234] = realOrder();

    $this->postJson('/api/conversions', purchase())->assertOk();

    $event = TrackingEvent::query()->sole();
    expect((float) $event->value)->toBe(42.5)
        ->and($event->currency)->toBe('CAD')
        ->and($event->shopify_order_id)->toBe(5551234)
        ->and($event->verified_at)->not->toBeNull();
    Http::assertSent(fn ($r) => $r->data()['data'][0]['custom_data']['value'] == 42.5 && $r->data()['data'][0]['custom_data']['currency'] === 'CAD');
    expect(PlatformDelivery::query()->where('status', 'delivered')->count())->toBe(1);
});

it('drops a forged purchase whose order does not exist (after retries)', function (): void {
    $this->postJson('/api/conversions', purchase(['transaction_id' => '999999999']))->assertOk();

    expect(TrackingEvent::query()->count())->toBe(0)
        ->and($this->orders->calls)->toBe(3);
    Http::assertNothingSent();
});

it('retries later while the order is not visible yet', function (): void {
    Queue::fake();
    ProcessTrackingEvent::make()->handle(new TrackingEventData(
        shopDomain: $this->shop->name, event: 'purchase', value: 1, currency: 'USD', transactionId: '5551234',
        gclid: null, fbp: null, fbc: null, ttclid: null, gaClientId: null, ip: '1.2.3.4', userAgent: null,
        idempotencyKey: 'k1', occurredAt: new DateTimeImmutable,
    ));

    expect(TrackingEvent::query()->count())->toBe(0);
    Queue::assertPushed(JobDecorator::class, fn ($job) => $job->getParameters()[1] === 1 && $job->delay !== null);
});

it('drops purchases without an order id, for orders older than 24h and replays of an order', function (): void {
    $this->orders->orders[5551234] = realOrder();
    $this->orders->orders[777] = realOrder(777, createdAt: '2026-11-20 10:00:00');

    $this->postJson('/api/conversions', purchase(['transaction_id' => 'c1-checkout-token']))->assertOk();
    $this->postJson('/api/conversions', purchase(['transaction_id' => '777']))->assertOk();
    expect(TrackingEvent::query()->count())->toBe(0);

    $this->postJson('/api/conversions', purchase(['idempotency_key' => 'first']))->assertOk();
    $this->postJson('/api/conversions', purchase(['idempotency_key' => 'replay', 'transaction_id' => '5551234']))->assertOk();

    expect(TrackingEvent::query()->pluck('idempotency_key')->all())->toBe(['first']);
    Http::assertSentCount(1);
});

it('does not verify another shop\'s order (lookup runs with the shop\'s own token)', function (): void {
    // The fake has no order for this id, as Shopify would answer for a foreign order.
    $this->postJson('/api/conversions', purchase(['transaction_id' => '123']))->assertOk();

    expect(TrackingEvent::query()->count())->toBe(0);
});

it('forwards as before without read_orders in auto mode, and drops in enforce mode', function (): void {
    $this->orders->scope = false;

    $this->postJson('/api/conversions', purchase(['idempotency_key' => 'a']))->assertOk();
    expect(TrackingEvent::query()->sole()->verified_at)->toBeNull()
        ->and((float) TrackingEvent::query()->sole()->value)->toBe(999999.0);

    config(['tracking.purchase_verification.mode' => 'enforce']);
    $this->postJson('/api/conversions', purchase(['idempotency_key' => 'b']))->assertOk();
    expect(TrackingEvent::query()->count())->toBe(1);
});

it('falls back after repeated Shopify errors only in auto mode', function (): void {
    $this->orders->queue = [ShopifyOrderResult::error('http_503'), ShopifyOrderResult::error('http_503'), ShopifyOrderResult::error('http_503')];
    $this->postJson('/api/conversions', purchase(['idempotency_key' => 'a']))->assertOk();
    expect(TrackingEvent::query()->count())->toBe(1);

    config(['tracking.purchase_verification.mode' => 'enforce']);
    $this->orders->queue = [ShopifyOrderResult::error('x'), ShopifyOrderResult::error('x'), ShopifyOrderResult::error('x')];
    $this->postJson('/api/conversions', purchase(['idempotency_key' => 'b']))->assertOk();
    expect(TrackingEvent::query()->count())->toBe(1);
});

it('leaves non-purchase events and the off mode untouched', function (): void {
    config(['tracking.purchase_verification.mode' => 'off']);

    $this->postJson('/api/conversions', purchase())->assertOk();
    $this->postJson('/api/conversions', purchase(['event' => 'add_to_cart', 'transaction_id' => null]))->assertOk();

    expect(TrackingEvent::query()->count())->toBe(2)->and($this->orders->calls)->toBe(0);
});

it('delays purchase processing so Shopify can create the order first', function (): void {
    Queue::fake();
    $this->postJson('/api/conversions', purchase())->assertOk();
    $this->postJson('/api/conversions', purchase(['event' => 'add_to_cart', 'transaction_id' => null]))->assertOk();

    $jobs = Queue::pushed(JobDecorator::class);
    expect($jobs)->toHaveCount(2)
        ->and($jobs->first()->delay)->not->toBeNull()
        ->and($jobs->last()->delay)->toBeNull();
});
