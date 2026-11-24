<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ShopifyOrderResult;
use App\Models\User;

/**
 * Reads a shop's order from the Shopify Admin API (needs the read_orders access scope).
 */
interface ShopifyOrderLookup
{
    /** Whether the app currently holds read_orders for this shop (cached). */
    public function canReadOrders(User $shop): bool;

    public function find(User $shop, int $orderId): ShopifyOrderResult;
}
