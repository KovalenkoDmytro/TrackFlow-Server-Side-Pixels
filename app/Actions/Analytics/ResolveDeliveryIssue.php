<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\DeliveryIssueResolution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Marks a shop's delivery issue (platform + error code) as resolved now. Failures up to
 * this moment stop counting; resolving again moves the moment forward.
 */
final class ResolveDeliveryIssue
{
    use AsObject;

    public function handle(User $shop, string $platform, string $code, ?CarbonImmutable $at = null): DeliveryIssueResolution
    {
        return DeliveryIssueResolution::query()->updateOrCreate(
            ['user_id' => $shop->getKey(), 'platform' => $platform, 'code' => $code],
            ['resolved_at' => $at ?? CarbonImmutable::now()],
        );
    }
}
