<?php

declare(strict_types=1);

namespace App\Actions\Gdpr;

use App\Mail\CustomerDataRequestMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * customers/data_request: compiles every tracking row TrackFlow stores for the customer
 * (FindCustomerTrackingEvents) and emails it to TrackFlow support (tracking.support_email),
 * who sends it to the merchant. Nothing is stored about the request itself.
 */
final class CompileCustomerData
{
    use AsJob, AsObject;

    private const int MAX_ROWS = 5000;

    public const array FIELDS = ['event', 'occurred_at', 'value', 'currency', 'transaction_id', 'ip', 'user_agent', 'fbp', 'fbc', 'gclid', 'ttclid', 'ga_client_id'];

    /**
     * @param  list<int|string>  $orderIds
     * @return list<array<string, mixed>>
     */
    public function handle(int $shopId, array $orderIds, ?string $customerId = null, ?string $requestId = null): array
    {
        $shop = User::query()->find($shopId);
        if (! $shop instanceof User) {
            return [];
        }

        $query = FindCustomerTrackingEvents::make()->handle($shop, $orderIds);
        $rows = $query === null ? [] : $query->orderBy('occurred_at')->limit(self::MAX_ROWS)->get()
            ->map(fn ($event) => collect(self::FIELDS)->mapWithKeys(fn (string $field) => [
                $field => $field === 'occurred_at' ? $event->occurred_at?->toIso8601String() : $event->getAttribute($field),
            ])->all())
            ->all();

        $recipient = config('tracking.support_email');
        if (is_string($recipient) && $recipient !== '') {
            Mail::to($recipient)->queue(new CustomerDataRequestMail((string) $shop->name, $orderIds, $customerId, $requestId, $rows));
        }

        Log::info('gdpr.customers_data_request.compiled', ['shop_id' => $shopId, 'orders' => count($orderIds), 'rows' => count($rows)]);

        return $rows;
    }
}
