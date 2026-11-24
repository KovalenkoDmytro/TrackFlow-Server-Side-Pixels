<?php

declare(strict_types=1);

namespace App\Actions\Gdpr;

use App\Actions\Tracking\VerifyPurchase;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Tracking rows that belong to a Shopify customer, for GDPR requests.
 *
 * TrackFlow stores no customer id, email or name. A customer is found through their orders:
 * the purchase events of those orders, plus every event of the same shop that shares one of
 * their browser identifiers (Meta _fbp cookie, GA client id), i.e. the same shopper's visits.
 */
final class FindCustomerTrackingEvents
{
    use AsObject;

    private const int MAX_ORDERS = 1000;

    /**
     * @param  list<int|string>  $orderIds
     * @return Builder<TrackingEvent>|null null when nothing matches
     */
    public function handle(User $shop, array $orderIds): ?Builder
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($id) => VerifyPurchase::orderId((string) $id),
            array_slice($orderIds, 0, self::MAX_ORDERS),
        ))));

        if ($ids === []) {
            return null;
        }

        $variants = [];
        foreach ($ids as $id) {
            array_push($variants, (string) $id, 'gid://shopify/Order/'.$id, 'gid://shopify/OrderIdentity/'.$id);
        }

        $purchases = TrackingEvent::query()
            ->where('user_id', $shop->getKey())
            ->where(fn (Builder $q) => $q->whereIn('shopify_order_id', $ids)->orWhereIn('transaction_id', $variants))
            ->get(['id', 'fbp', 'ga_client_id']);

        if ($purchases->isEmpty()) {
            return null;
        }

        $fbps = $purchases->pluck('fbp')->filter()->unique()->values()->all();
        $gaIds = $purchases->pluck('ga_client_id')->filter()->unique()->values()->all();

        return TrackingEvent::query()
            ->where('user_id', $shop->getKey())
            ->where(function (Builder $q) use ($purchases, $fbps, $gaIds): void {
                $q->whereIn('id', $purchases->pluck('id')->all());
                if ($fbps !== []) {
                    $q->orWhereIn('fbp', $fbps);
                }
                if ($gaIds !== []) {
                    $q->orWhereIn('ga_client_id', $gaIds);
                }
            });
    }
}
