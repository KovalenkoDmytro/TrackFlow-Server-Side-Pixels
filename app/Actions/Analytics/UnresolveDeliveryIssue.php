<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\DeliveryIssueResolution;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Undo for ResolveDeliveryIssue: the issue's earlier failures count again.
 * Returns whether a resolution existed.
 */
final class UnresolveDeliveryIssue
{
    use AsObject;

    public function handle(User $shop, string $platform, string $code): bool
    {
        return DeliveryIssueResolution::query()
            ->where('user_id', $shop->getKey())
            ->where('platform', $platform)
            ->where('code', $code)
            ->delete() > 0;
    }
}
