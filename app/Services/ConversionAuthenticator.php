<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Authenticates an inbound conversion tracking request.
 *
 * Responsible for two concerns that belong together:
 *  1. Resolving a shop record by its myshopify domain.
 *  2. Verifying the per-shop tracking_secret against the value sent in the request.
 *
 * Separated from ConversionController so the same logic can be reused in tests
 * or alternative entry points without duplicating it.
 */
final class ConversionAuthenticator
{
    private const string DUMMY_SECRET = '0000000000000000000000000000000000000000000000000000000000000000';

    private static function unauthorized(): UnauthorizedHttpException
    {
        return new UnauthorizedHttpException('', 'Unauthorized.');
    }

    /**
     * Resolve and authenticate the shop for an inbound conversion tracking request.
     *
     * @param  string  $shopDomain  The myshopify domain sent in the request body.
     * @param  string  $trackingSecret  The raw secret sent in the request body.
     * @return User The authenticated shop record.
     *
     * @throws UnauthorizedHttpException The same 401 for an unknown shop, a shop that is not
     *                                   installed and a wrong secret, so the endpoint cannot be
     *                                   used to find out which shops use TrackFlow.
     */
    public function authenticate(string $shopDomain, string $trackingSecret): User
    {
        $shop = User::query()
            ->where('name', $shopDomain)
            ->first();

        if (! $shop instanceof User) {
            // Same work as a real comparison so response time does not reveal unknown shops.
            hash_equals(self::DUMMY_SECRET, $trackingSecret);

            throw self::unauthorized();
        }

        if (! $this->isValidSecret($shop, $trackingSecret) || blank($shop->password)) {
            throw self::unauthorized();
        }

        return $shop;
    }

    /**
     * Compare the provided secret against the stored one using a timing-safe comparison.
     *
     * hash_equals() prevents timing attacks where an attacker could infer
     * the correct secret by measuring response time differences.
     */
    private function isValidSecret(User $shop, string $providedSecret): bool
    {
        $storedSecret = $shop->tracking_secret;

        if ($storedSecret === null) {
            return false;
        }

        return hash_equals($storedSecret, $providedSecret);
    }
}
