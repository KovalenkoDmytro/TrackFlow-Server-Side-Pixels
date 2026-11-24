<?php

declare(strict_types=1);

namespace App\Actions\Gdpr;

use App\Models\PlatformDelivery;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * customers/redact: anonymises the customer's tracking rows (FindCustomerTrackingEvents).
 * Personal identifiers (IP, user agent, click ids, cookies, order id) are removed; the event
 * type, value and time stay so the merchant's aggregate reports keep their totals. The stored
 * platform responses of those events are cleared too, since error bodies can echo click ids.
 */
final class RedactCustomerData
{
    use AsJob, AsObject;

    public const array PERSONAL_COLUMNS = ['transaction_id', 'shopify_order_id', 'gclid', 'gclid_hash', 'fbp', 'fbc', 'ttclid', 'ga_client_id', 'ip', 'user_agent'];

    /**
     * @param  list<int|string>  $orderIds
     */
    public function handle(int $shopId, array $orderIds): int
    {
        $shop = User::query()->find($shopId);
        if (! $shop instanceof User) {
            return 0;
        }

        $query = FindCustomerTrackingEvents::make()->handle($shop, $orderIds);
        if ($query === null) {
            $count = 0;
        } else {
            $ids = $query->pluck('id')->all();
            $count = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $count += TrackingEvent::query()->whereIn('id', $chunk)->update(array_fill_keys(self::PERSONAL_COLUMNS, null));
                PlatformDelivery::query()->whereIn('tracking_event_id', $chunk)->update(['response_body' => null]);
            }
        }

        Log::info('gdpr.customers_redact.done', ['shop_id' => $shopId, 'orders' => count($orderIds), 'events_anonymised' => $count]);

        return $count;
    }
}
