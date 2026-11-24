<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Actions\Gdpr\RedactCustomerData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * Handles the mandatory GDPR "customers/redact" webhook.
 *
 * The app stores no customer id, email, phone, name or address, but tracking rows hold
 * personal identifiers (IP, user agent, click ids, cookies, order id). RedactCustomerData
 * (queued) anonymises the rows linked to `orders_to_redact`.
 *
 * HMAC verification is handled upstream by the `auth.webhook` middleware
 * (Osiset\ShopifyApp\Http\Middleware\AuthWebhook) — this Action only runs
 * for requests with a valid Shopify signature.
 */
final class CustomersRedactWebhook
{
    use AsController;

    public function handle(Request $request): Response
    {
        // Resolve the shop from the signed JSON body (`shop_domain`), not the
        // `X-Shopify-Shop-Domain` header, since the header is not covered by
        // the HMAC verified in AuthWebhook and cannot be trusted.
        $shopDomain = $request->input('shop_domain');

        Log::info('Received customers/redact webhook', [
            'shop_domain' => $shopDomain,
        ]);

        $shop = is_string($shopDomain) ? User::query()->where('name', $shopDomain)->first() : null;
        $orders = $request->input('orders_to_redact');

        if ($shop instanceof User && is_array($orders) && $orders !== []) {
            RedactCustomerData::dispatch((int) $shop->getKey(), array_values($orders));
        }

        return response('', 200);
    }
}
