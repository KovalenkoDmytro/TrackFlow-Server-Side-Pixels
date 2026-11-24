<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\DeliveryIssueResolution;
use App\Models\SupportReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Marks a shop's delivery issue (platform + error code) as resolved now. Failures up to
 * this moment stop counting; resolving again moves the moment forward. Closes an open
 * support report for the same issue.
 */
final class ResolveDeliveryIssue
{
    use AsObject;

    public function handle(User $shop, string $platform, string $code, ?CarbonImmutable $at = null): DeliveryIssueResolution
    {
        $at ??= CarbonImmutable::now();

        // An open support report for this issue is closed too: if the error comes back
        // later, the merchant can send it to support again.
        SupportReport::query()->open()
            ->where('user_id', $shop->getKey())->where('platform', $platform)->where('code', $code)
            ->update(['closed_at' => $at]);

        return DeliveryIssueResolution::query()->updateOrCreate(
            ['user_id' => $shop->getKey(), 'platform' => $platform, 'code' => $code],
            ['resolved_at' => $at],
        );
    }
}
