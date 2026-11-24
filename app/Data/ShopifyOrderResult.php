<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class ShopifyOrderResult
{
    public const string FOUND = 'found';

    public const string NOT_FOUND = 'not_found';

    /** API error, throttling or missing scope: try again later. */
    public const string ERROR = 'error';

    private function __construct(
        public string $status,
        public ?int $orderId = null,
        public ?float $value = null,
        public ?string $currency = null,
        public ?CarbonImmutable $createdAt = null,
        public ?string $reason = null,
    ) {}

    public static function found(int $orderId, float $value, string $currency, CarbonImmutable $createdAt): self
    {
        return new self(self::FOUND, $orderId, $value, $currency, $createdAt);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public static function error(string $reason): self
    {
        return new self(self::ERROR, reason: $reason);
    }
}
