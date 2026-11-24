<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Mail\CustomerDataRequestMail;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config(['shopify-app.api_secret' => 'test-shopify-secret', 'tracking.support_email' => 'support@example.test']);
    Mail::fake();
    $this->shop = User::factory()->create(['name' => 'gdpr-shop.myshopify.com']);
    $this->other = User::factory()->create(['name' => 'other-shop.myshopify.com']);
});

function gdprEvent(User $shop, array $attrs): TrackingEvent
{
    return TrackingEvent::query()->create([
        'user_id' => $shop->getKey(),
        'event' => 'view_item',
        'value' => 10,
        'currency' => 'CAD',
        'ip' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0',
        'occurred_at' => now(),
        ...$attrs,
    ]);
}

function signedWebhook(object $test, string $uri, array $payload): TestResponse
{
    return $test->postJson($uri, $payload, [
        'X-Shopify-Hmac-Sha256' => signShopifyWebhookPayload(json_encode($payload)),
        'X-Shopify-Shop-Domain' => $payload['shop_domain'],
    ]);
}

/** The customer's purchase (order 299938), an earlier visit with the same _fbp, and unrelated rows. */
function gdprFixture(object $test): array
{
    $purchase = gdprEvent($test->shop, ['event' => 'purchase', 'transaction_id' => 'gid://shopify/OrderIdentity/299938', 'fbp' => 'fb.1.111.CUST', 'ga_client_id' => '111.222', 'gclid' => 'GCLID-CUST', 'fbc' => 'fb.1.1.FBCLID']);
    $visit = gdprEvent($test->shop, ['fbp' => 'fb.1.111.CUST']);
    $gaVisit = gdprEvent($test->shop, ['ga_client_id' => '111.222']);
    $stranger = gdprEvent($test->shop, ['fbp' => 'fb.1.999.OTHER', 'gclid' => 'GCLID-OTHER']);
    $otherShop = gdprEvent($test->other, ['event' => 'purchase', 'transaction_id' => '299938', 'fbp' => 'fb.1.111.CUST']);

    return compact('purchase', 'visit', 'gaVisit', 'stranger', 'otherShop');
}

it('customers/redact anonymises the customer\'s rows in that shop only', function (): void {
    $rows = gdprFixture($this);

    signedWebhook($this, '/webhook/customers-redact', [
        'shop_id' => 1, 'shop_domain' => 'gdpr-shop.myshopify.com',
        'customer' => ['id' => 191167, 'email' => 'john@example.com'],
        'orders_to_redact' => [299938, 280263],
    ])->assertOk();

    foreach (['purchase', 'visit', 'gaVisit'] as $key) {
        $event = $rows[$key]->fresh();
        foreach (['transaction_id', 'fbp', 'fbc', 'gclid', 'gclid_hash', 'ga_client_id', 'ip', 'user_agent'] as $column) {
            expect($event->getAttribute($column))->toBeNull("{$key}.{$column}");
        }
        expect((float) $event->value)->toBe(10.0)->and($event->event)->not->toBeEmpty();
    }

    expect($rows['stranger']->fresh()->fbp)->toBe('fb.1.999.OTHER')
        ->and($rows['stranger']->fresh()->ip)->toBe('203.0.113.7')
        ->and($rows['otherShop']->fresh()->fbp)->toBe('fb.1.111.CUST')
        ->and($rows['otherShop']->fresh()->transaction_id)->toBe('299938');
});

it('customers/redact is a no-op for unknown orders and unknown shops', function (): void {
    $rows = gdprFixture($this);

    signedWebhook($this, '/webhook/customers-redact', ['shop_domain' => 'gdpr-shop.myshopify.com', 'orders_to_redact' => [1]])->assertOk();
    signedWebhook($this, '/webhook/customers-redact', ['shop_domain' => 'nobody.myshopify.com', 'orders_to_redact' => [299938]])->assertOk();
    signedWebhook($this, '/webhook/customers-redact', ['shop_domain' => 'gdpr-shop.myshopify.com', 'orders_to_redact' => []])->assertOk();

    expect($rows['purchase']->fresh()->fbp)->toBe('fb.1.111.CUST');
});

it('customers/redact also finds verified purchases by shopify_order_id', function (): void {
    $event = gdprEvent($this->shop, ['event' => 'purchase', 'transaction_id' => 'c1-token', 'shopify_order_id' => 280263, 'fbp' => 'fb.1.5.X']);

    signedWebhook($this, '/webhook/customers-redact', ['shop_domain' => 'gdpr-shop.myshopify.com', 'orders_to_redact' => [280263]])->assertOk();

    expect($event->fresh()->fbp)->toBeNull()->and($event->fresh()->shopify_order_id)->toBeNull();
});

it('customers/data_request emails support every stored row for the customer', function (): void {
    gdprFixture($this);

    signedWebhook($this, '/webhook/customers-data-request', [
        'shop_id' => 1, 'shop_domain' => 'gdpr-shop.myshopify.com',
        'customer' => ['id' => 191167, 'email' => 'john@example.com'],
        'orders_requested' => [299938],
        'data_request' => ['id' => 9999],
    ])->assertOk();

    Mail::assertQueued(CustomerDataRequestMail::class, function (CustomerDataRequestMail $mail): bool {
        $text = view('mail.customer-data-request', get_object_vars($mail))->render();

        expect($mail->hasTo('support@example.test'))->toBeTrue()
            ->and($mail->rows)->toHaveCount(3)
            ->and(collect($mail->rows)->pluck('fbp')->filter()->unique()->values()->all())->toBe(['fb.1.111.CUST'])
            ->and($mail->customerId)->toBe('191167')
            ->and($mail->requestId)->toBe('9999')
            ->and($text)->toContain('gdpr-shop.myshopify.com')->toContain('GCLID-CUST')->toContain('203.0.113.7')
            ->not->toContain('GCLID-OTHER');

        return true;
    });
});

it('customers/data_request reports when nothing is stored', function (): void {
    signedWebhook($this, '/webhook/customers-data-request', ['shop_domain' => 'gdpr-shop.myshopify.com', 'orders_requested' => [42]])->assertOk();

    Mail::assertQueued(CustomerDataRequestMail::class, fn (CustomerDataRequestMail $m) => $m->rows === []
        && str_contains(view('mail.customer-data-request', get_object_vars($m))->render(), 'No tracking data is stored'));
});

it('customers/redact clears the stored platform responses of the redacted events', function (): void {
    $rows = gdprFixture($this);
    $integration = PlatformIntegration::query()->create([
        'user_id' => $this->shop->getKey(),
        'platform' => Platform::GoogleAds,
        'active' => true,
        'credentials' => json_encode(['token' => 'x']),
        'settings' => [],
    ]);
    $delivery = fn (TrackingEvent $event) => PlatformDelivery::query()->create([
        'tracking_event_id' => $event->getKey(),
        'platform_integration_id' => $integration->getKey(),
        'platform' => 'google_ads',
        'status' => 'failed',
        'response_body' => 'Click GCLID-CUST not found',
    ]);
    $mine = $delivery($rows['purchase']);
    $theirs = $delivery($rows['stranger']);

    signedWebhook($this, '/webhook/customers-redact', ['shop_domain' => 'gdpr-shop.myshopify.com', 'orders_to_redact' => [299938]])->assertOk();

    expect($mine->fresh()->response_body)->toBeNull()
        ->and($mine->fresh()->status)->toBe('failed')
        ->and($theirs->fresh()->response_body)->toBe('Click GCLID-CUST not found');
});
