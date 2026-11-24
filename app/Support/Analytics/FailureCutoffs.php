<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\DeliveryIssueResolution;
use App\Models\PlatformIntegration;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Per shop: up to which moment failures of a platform + error code no longer count
 * toward issue cards and the health score.
 *
 *   - the merchant marked the issue resolved (delivery_issue_resolutions.resolved_at), or
 *   - for sign-in / token codes, the integration was reconnected afterwards (connected_at).
 *
 * The later of the two wins. A failure counts only when its last attempt
 * (platform_deliveries.updated_at) is after the cutoff, so a recurring error comes back.
 */
final class FailureCutoffs
{
    /**
     * @param  array<string, CarbonImmutable>  $resolvedAt  keyed by "platform|code"
     * @param  array<string, CarbonImmutable>  $connectedAt  keyed by platform
     */
    public function __construct(
        private readonly array $resolvedAt = [],
        private readonly array $connectedAt = [],
    ) {}

    public static function forShop(User $shop): self
    {
        $resolved = [];
        foreach (DeliveryIssueResolution::query()->where('user_id', $shop->getKey())->get() as $resolution) {
            $resolved[$resolution->platform.'|'.$resolution->code] = CarbonImmutable::instance($resolution->resolved_at);
        }

        $connected = [];
        foreach (PlatformIntegration::query()->where('user_id', $shop->getKey())->whereNotNull('connected_at')->get() as $integration) {
            $connected[$integration->platform->value] = CarbonImmutable::instance($integration->connected_at);
        }

        return new self($resolved, $connected);
    }

    public function resolvedAt(string $platform, string $code): ?CarbonImmutable
    {
        return $this->resolvedAt[$platform.'|'.$code] ?? null;
    }

    public function cutoff(string $platform, string $code): ?CarbonImmutable
    {
        $resolved = $this->resolvedAt($platform, $code);
        $connected = DeliveryErrorCode::isAuthCode($code) ? ($this->connectedAt[$platform] ?? null) : null;

        if ($resolved === null || $connected === null) {
            return $resolved ?? $connected;
        }

        return $resolved->greaterThan($connected) ? $resolved : $connected;
    }

    /** Latest cutoff of any code on the platform; rows after it never need classifying. */
    public function latestCutoff(string $platform): ?CarbonImmutable
    {
        $latest = $this->connectedAt[$platform] ?? null;

        foreach ($this->resolvedAt as $key => $at) {
            if (str_starts_with($key, $platform.'|') && ($latest === null || $at->greaterThan($latest))) {
                $latest = $at;
            }
        }

        return $latest;
    }

    public function ignores(string $platform, string $code, DateTimeInterface|string|null $failedAt): bool
    {
        $cutoff = $this->cutoff($platform, $code);

        if ($cutoff === null || $failedAt === null) {
            return false;
        }

        return CarbonImmutable::parse($failedAt)->lessThanOrEqualTo($cutoff);
    }
}
