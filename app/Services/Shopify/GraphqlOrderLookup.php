<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Contracts\ShopifyOrderLookup;
use App\Data\ShopifyOrderResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Admin GraphQL implementation. The query runs with the shop's own offline token, so an
 * order id from another shop is never found.
 */
final class GraphqlOrderLookup implements ShopifyOrderLookup
{
    private const int SCOPE_CACHE_SECONDS = 3600;

    public function canReadOrders(User $shop): bool
    {
        return (bool) Cache::remember('shopify:read_orders:'.$shop->getKey(), self::SCOPE_CACHE_SECONDS, function () use ($shop): bool {
            try {
                $response = $shop->api()->graph('query { currentAppInstallation { accessScopes { handle } } }');
            } catch (Throwable) {
                return false;
            }

            if (! empty($response['errors'])) {
                return false;
            }

            $handles = collect($response['body']['data']['currentAppInstallation']['accessScopes'] ?? [])
                ->map(fn ($scope) => is_array($scope) || $scope instanceof \ArrayAccess ? (string) ($scope['handle'] ?? '') : '')
                ->all();

            return in_array('read_orders', $handles, true) || in_array('write_orders', $handles, true);
        });
    }

    public function find(User $shop, int $orderId): ShopifyOrderResult
    {
        $query = <<<'GQL'
            query TrackFlowOrder($id: ID!) {
                order(id: $id) {
                    legacyResourceId
                    createdAt
                    totalPriceSet { presentmentMoney { amount currencyCode } }
                }
            }
        GQL;

        try {
            $response = $shop->api()->graph($query, ['id' => 'gid://shopify/Order/'.$orderId]);
        } catch (Throwable $e) {
            return ShopifyOrderResult::error($e::class);
        }

        if (! empty($response['errors'])) {
            return ShopifyOrderResult::error($response['errors'] === true ? 'http_'.($response['status'] ?? '?') : 'graphql_error');
        }

        $order = $response['body']['data']['order'] ?? null;
        if ($order === null) {
            return ShopifyOrderResult::notFound();
        }

        $money = $order['totalPriceSet']['presentmentMoney'] ?? null;

        if ($money === null || ! isset($money['amount'], $money['currencyCode'], $order['createdAt'])) {
            return ShopifyOrderResult::error('incomplete_order');
        }

        return ShopifyOrderResult::found(
            (int) ($order['legacyResourceId'] ?? $orderId),
            (float) $money['amount'],
            (string) $money['currencyCode'],
            CarbonImmutable::parse((string) $order['createdAt']),
        );
    }
}
