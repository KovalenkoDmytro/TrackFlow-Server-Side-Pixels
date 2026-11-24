<?php

declare(strict_types=1);

use App\Support\Analytics\DeliveryRate;

describe('DeliveryRate', function (): void {
    it('divides accepted by eligible uploads', function (): void {
        expect(DeliveryRate::percent(26, 35))->toBe(74.3);
    });

    it('excludes other_account, expired and pending rows from eligible', function (): void {
        // 10 attempted: 6 delivered, 1 failed, 1 pending, 1 other account, 1 expired → 6 / 7
        expect(DeliveryRate::eligible(10, 1, 1, 1))->toBe(7)
            ->and(DeliveryRate::percent(6, 10, 1, 1, 1))->toBe(85.7);
    });

    it('returns null when nothing is eligible', function (): void {
        expect(DeliveryRate::percent(0, 0))->toBeNull()
            ->and(DeliveryRate::percent(0, 3, 2, 1))->toBeNull();
    });

    it('is 100 when every eligible upload was accepted', function (): void {
        expect(DeliveryRate::percent(1000, 1000))->toBe(100.0)
            ->and(DeliveryRate::percent(52, 58, 4, 2))->toBe(100.0);
    });

    it('reads a delivery stat row', function (): void {
        $stat = ['attempted' => 19, 'delivered' => 10, 'failed' => 9, 'pending' => 0, 'other_account' => 0, 'expired' => 0];

        expect(DeliveryRate::forStat($stat))->toBe(52.6)
            ->and(DeliveryRate::eligibleForStat($stat))->toBe(19);
    });
});
