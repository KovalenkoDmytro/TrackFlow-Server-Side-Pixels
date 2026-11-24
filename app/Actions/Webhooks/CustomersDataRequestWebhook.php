<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Actions\Gdpr\CompileCustomerData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * Handles the mandatory GDPR "customers/data_request" webhook.
 *
 * The app stores no customer id, email, phone, name or address. CompileCustomerData (queued)
 * gathers the tracking rows linked to `orders_requested` and emails them to TrackFlow support,
 * who passes them to the merchant.
 *
 * HMAC verification is handled upstream by the `auth.webhook` middleware
 * (Osiset\ShopifyApp\Http\Middleware\AuthWebhook) — this Action only runs
 * for requests with a valid Shopify signature.
 */
final class CustomersDataRequestWebhook
{
    use AsController;

    public function handle(Request $request): Response
    {
        // Resolve the shop from the signed JSON body (`shop_domain`), not the
        // `X-Shopify-Shop-Domain` header, since the header is not covered by
        // the HMAC verified in AuthWebhook and cannot be trusted.
        $shopDomain = $request->input('shop_domain');

        Log::info('Received customers/data_request webhook', [
            'shop_domain' => $shopDomain,
        ]);

        $shop = is_string($shopDomain) ? User::query()->where('name', $shopDomain)->first() : null;
        $orders = $request->input('orders_requested');

        if ($shop instanceof User) {
            CompileCustomerData::dispatch(
                (int) $shop->getKey(),
                is_array($orders) ? array_values($orders) : [],
                $request->filled('customer.id') ? (string) $request->input('customer.id') : null,
                $request->filled('data_request.id') ? (string) $request->input('data_request.id') : null,
            );
        }

        return response('', 200);
    }
}
