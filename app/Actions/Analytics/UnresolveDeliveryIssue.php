<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\DeliveryIssueResolution;
use App\Models\SupportReport;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Undo for ResolveDeliveryIssue: the issue's earlier failures count again and a support
 * report closed by the resolution is open again.
 * Returns whether a resolution existed.
 */
final class UnresolveDeliveryIssue
{
    use AsObject;

    public function handle(User $shop, string $platform, string $code): bool
    {
        $resolution = DeliveryIssueResolution::query()
            ->where('user_id', $shop->getKey())
            ->where('platform', $platform)
            ->where('code', $code)
            ->first();

        if ($resolution === null) {
            return false;
        }

        // Reopen the support report that this resolution closed (same moment or later).
        SupportReport::query()
            ->where('user_id', $shop->getKey())->where('platform', $platform)->where('code', $code)
            ->where('closed_at', '>=', $resolution->resolved_at)
            ->update(['closed_at' => null]);

        return (bool) $resolution->delete();
    }
}
