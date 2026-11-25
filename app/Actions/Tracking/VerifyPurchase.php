<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Contracts\ShopifyOrderLookup;
use App\Data\ShopifyOrderResult;
use App\Data\TrackingEventData;
use App\Models\TrackingEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Decides whether a purchase from the (public) pixel endpoint may be forwarded to ad platforms.
 *
 * With read_orders granted, a purchase is only forwarded when its order exists in that shop,
 * was created recently and was not already counted; value and currency are taken from Shopify.
 * Without the scope (or without an order id, or when Shopify keeps failing),
 * tracking.purchase_verification decides: "auto" forwards unverified as before, "enforce"
 * drops the purchase, "off" skips verification entirely. Forged order ids, stale orders and
 * replays are dropped in both auto and enforce mode.
 *
 * @phpstan-type Verdict array{action: 'deliver'|'retry'|'reject', data: TrackingEventData, order_id: int|null, verified: bool, reason: string}
 */
final class VerifyPurchase
{
    use AsObject;

    public const string MODE_OFF = 'off';

    public const string MODE_AUTO = 'auto';

    public const string MODE_ENFORCE = 'enforce';

    public function __construct(private readonly ShopifyOrderLookup $orders) {}

    public static function mode(): string
    {
        $mode = (string) config('tracking.purchase_verification.mode', self::MODE_AUTO);

        return in_array($mode, [self::MODE_OFF, self::MODE_AUTO, self::MODE_ENFORCE], true) ? $mode : self::MODE_AUTO;
    }

    /**
     * Shopify order id from the pixel's transaction id: "123", "gid://shopify/Order/123" or
     * "gid://shopify/OrderIdentity/123". Checkout tokens and anything else give null.
     */
    public static function orderId(?string $transactionId): ?int
    {
        if ($transactionId === null) {
            return null;
        }

        if (preg_match('~^(?:gid://shopify/(?:Order|OrderIdentity)/)?([1-9][0-9]{0,18})$~D', trim($transactionId), $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * @return Verdict
     */
    public function handle(User $shop, TrackingEventData $data, int $attempt): array
    {
        $mode = self::mode();
        $lastAttempt = $attempt >= (int) config('tracking.purchase_verification.max_attempts', 3) - 1;

        if ($mode === self::MODE_OFF) {
            return $this->verdict('deliver', $data, reason: 'verification_off');
        }

        if (! $this->orders->canReadOrders($shop)) {
            return $mode === self::MODE_ENFORCE
                ? $this->verdict('reject', $data, reason: 'no_read_orders_scope')
                : $this->verdict('deliver', $data, reason: 'no_read_orders_scope');
        }

        $orderId = self::orderId($data->transactionId);
        if ($orderId === null) {
            // The pixel falls back to the checkout token when Shopify gives no order id yet; such a
            // purchase cannot be checked. Auto mode forwards it unverified rather than losing a
            // real sale; enforce mode drops it.
            return $mode === self::MODE_ENFORCE
                ? $this->verdict('reject', $data, reason: 'no_order_id')
                : $this->verdict('deliver', $data, reason: 'no_order_id');
        }

        $result = $this->orders->find($shop, $orderId);

        if ($result->status === ShopifyOrderResult::NOT_FOUND) {
            return $this->verdict($lastAttempt ? 'reject' : 'retry', $data, $orderId, reason: 'order_not_found');
        }

        if ($result->status === ShopifyOrderResult::ERROR) {
            if (! $lastAttempt) {
                return $this->verdict('retry', $data, $orderId, reason: 'lookup_error:'.$result->reason);
            }

            // Shopify kept failing: never caused by the client, so fall back like a missing scope.
            return $mode === self::MODE_ENFORCE
                ? $this->verdict('reject', $data, $orderId, reason: 'lookup_error:'.$result->reason)
                : $this->verdict('deliver', $data, $orderId, reason: 'lookup_error:'.$result->reason);
        }

        $maxAge = (int) config('tracking.purchase_verification.max_order_age_hours', 24);
        if ($result->createdAt !== null && $result->createdAt->lessThan(CarbonImmutable::now()->subHours($maxAge))) {
            return $this->verdict('reject', $data, $orderId, reason: 'order_too_old');
        }

        $canonical = (int) $result->orderId;
        $duplicate = TrackingEvent::query()
            ->where('user_id', $shop->getKey())
            ->where('shopify_order_id', $canonical)
            ->when($data->idempotencyKey !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('idempotency_key')->orWhere('idempotency_key', '!=', $data->idempotencyKey)))
            ->exists();

        if ($duplicate) {
            return $this->verdict('reject', $data, $canonical, reason: 'order_already_tracked');
        }

        return $this->verdict('deliver', $data->withMoney((float) $result->value, (string) $result->currency), $canonical, verified: true, reason: 'verified');
    }

    /**
     * @return Verdict
     */
    private function verdict(string $action, TrackingEventData $data, ?int $orderId = null, bool $verified = false, string $reason = ''): array
    {
        return ['action' => $action, 'data' => $data, 'order_id' => $orderId, 'verified' => $verified, 'reason' => $reason];
    }
}
