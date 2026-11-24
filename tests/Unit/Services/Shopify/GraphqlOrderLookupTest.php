<?php

declare(strict_types=1);

use App\Data\ShopifyOrderResult;
use App\Models\User;
use App\Services\Shopify\GraphqlOrderLookup;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Illuminate\Support\Facades\Cache;

/** A shop whose Admin API returns canned GraphQL responses; calls are recorded on ->calls. */
function shopAnswering(array ...$responses): User
{
    $recorder = new ArrayObject;
    $api = Mockery::mock(BasicShopifyAPI::class);
    $api->shouldReceive('graph')->andReturnUsing(function (string $query, array $vars = []) use (&$responses, $recorder): array {
        $recorder->append($vars);

        return array_shift($responses);
    });

    $shop = new class extends User
    {
        public ?BasicShopifyAPI $fakeApi = null;

        public ?ArrayObject $calls = null;

        public function api(): BasicShopifyAPI
        {
            return $this->fakeApi;
        }
    };
    $shop->fakeApi = $api;
    $shop->calls = $recorder;
    $shop->id = random_int(1000, 999999);

    return $shop;
}

function gql(array $data): array
{
    return ['errors' => false, 'status' => 200, 'body' => ['data' => $data]];
}

it('reads the order total and creation time in presentment currency', function (): void {
    $shop = shopAnswering(gql(['order' => ['legacyResourceId' => '42', 'createdAt' => '2026-11-24T17:00:00Z', 'totalPriceSet' => ['presentmentMoney' => ['amount' => '19.90', 'currencyCode' => 'EUR']]]]));

    $result = (new GraphqlOrderLookup)->find($shop, 42);

    expect($result->status)->toBe(ShopifyOrderResult::FOUND)
        ->and($result->value)->toBe(19.9)->and($result->currency)->toBe('EUR')
        ->and($result->createdAt->toIso8601String())->toBe('2026-11-24T17:00:00+00:00')
        ->and($shop->calls[0])->toBe(['id' => 'gid://shopify/Order/42']);
});

it('maps a missing order to not found and API failures to errors', function (): void {
    expect((new GraphqlOrderLookup)->find(shopAnswering(gql(['order' => null])), 1)->status)->toBe(ShopifyOrderResult::NOT_FOUND)
        ->and((new GraphqlOrderLookup)->find(shopAnswering(['errors' => true, 'status' => 503, 'body' => null]), 1)->reason)->toBe('http_503')
        ->and((new GraphqlOrderLookup)->find(shopAnswering(['errors' => [['message' => 'Access denied']], 'status' => 200, 'body' => []]), 1)->status)->toBe(ShopifyOrderResult::ERROR);
});

it('detects the read_orders scope and caches the answer', function (): void {
    Cache::flush();
    $with = shopAnswering(gql(['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_pixels'], ['handle' => 'read_orders']]]]));
    $without = shopAnswering(gql(['currentAppInstallation' => ['accessScopes' => [['handle' => 'read_pixels']]]]));

    $lookup = new GraphqlOrderLookup;
    expect($lookup->canReadOrders($with))->toBeTrue()
        ->and($lookup->canReadOrders($with))->toBeTrue()
        ->and($with->calls->getArrayCopy())->toHaveCount(1)
        ->and($lookup->canReadOrders($without))->toBeFalse();
});
