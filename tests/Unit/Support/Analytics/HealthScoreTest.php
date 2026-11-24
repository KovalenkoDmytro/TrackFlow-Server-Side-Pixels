<?php

declare(strict_types=1);

use App\Support\Analytics\HealthScore;

function hsStat(string $event, int $attempted, int $delivered, int $failed, int $pending = 0, int $otherAccount = 0, int $expired = 0): array
{
    return ['event' => $event, 'attempted' => $attempted, 'delivered' => $delivered, 'failed' => $failed, 'pending' => $pending, 'other_account' => $otherAccount, 'expired' => $expired];
}

describe('HealthScore weights', function (): void {
    it('weights purchase 1, cart and checkout steps 0.5 and browse events 0.2', function (): void {
        expect(HealthScore::weight('purchase'))->toBe(1.0)
            ->and(HealthScore::weight('begin_checkout'))->toBe(0.5)
            ->and(HealthScore::weight('add_payment_info'))->toBe(0.5)
            ->and(HealthScore::weight('add_shipping_info'))->toBe(0.5)
            ->and(HealthScore::weight('add_to_cart'))->toBe(0.5)
            ->and(HealthScore::weight('view_item'))->toBe(0.2)
            ->and(HealthScore::weight('search'))->toBe(0.2)
            ->and(HealthScore::weight('unknown_event'))->toBe(0.2);
    });
});

describe('HealthScore::platformPenalty', function (): void {
    it('scores the real Nov 24 Google Ads day (9 of 19 View Item failed) as a small penalty', function (): void {
        $stats = [
            hsStat('purchase', 1, 1, 0),
            hsStat('begin_checkout', 1, 1, 0),
            hsStat('add_payment_info', 13, 13, 0),
            hsStat('add_shipping_info', 1, 1, 0),
            hsStat('view_item', 19, 10, 9),
        ];

        // weighted failures 9 × 0.2 = 1.8 over 35 eligible = 5.14 pts; (5.14 − 1) × 3 = 12.4
        expect(HealthScore::platformPenalty($stats))->toBe(12.4);
    });

    it('penalises a failed purchase five times more than a failed product view', function (): void {
        $purchase = HealthScore::platformPenalty([hsStat('purchase', 100, 96, 4)]);
        $view = HealthScore::platformPenalty([hsStat('view_item', 100, 96, 4)]);

        expect($purchase)->toBe(9.0)   // 4% − 1 = 3 × 3
            ->and($view)->toBe(0.0);   // 0.8% is within the tolerated 1 point
    });

    it('caps the penalty at 30 points per platform', function (): void {
        expect(HealthScore::platformPenalty([hsStat('purchase', 10, 0, 10)]))->toBe(30.0);
    });

    it('ignores other_account, expired and pending rows', function (): void {
        expect(HealthScore::platformPenalty([hsStat('purchase', 10, 10, 0, 3, 4, 5)]))->toBe(0.0)
            ->and(HealthScore::platformPenalty([hsStat('purchase', 5, 0, 0, 1, 2, 2)]))->toBe(0.0);
    });
});

describe('HealthScore::calculate', function (): void {
    it('is 100 and healthy when everything is delivered', function (): void {
        $result = HealthScore::calculate(true, true, true, ['meta' => [hsStat('purchase', 19, 19, 0), hsStat('view_item', 723, 723, 0)]]);

        expect($result)->toMatchArray(['score' => 100, 'status' => 'healthy', 'deductions' => []]);
    });

    it('scores the real Nov 24 store at 88 (attention), driven only by Google Ads', function (): void {
        $result = HealthScore::calculate(true, true, true, [
            'meta' => [hsStat('purchase', 19, 19, 0), hsStat('view_item', 723, 723, 0), hsStat('view_cart', 90, 90, 0)],
            'google_ads' => [hsStat('purchase', 1, 1, 0), hsStat('begin_checkout', 1, 1, 0), hsStat('add_payment_info', 13, 13, 0), hsStat('add_shipping_info', 1, 1, 0), hsStat('view_item', 19, 10, 9)],
        ]);

        expect($result['score'])->toBe(88)
            ->and($result['status'])->toBe('attention')
            ->and($result['deductions'])->toHaveCount(1)
            ->and($result['deductions'][0])->toMatchArray(['key' => 'delivery_failures', 'platform' => 'google_ads', 'points' => 12.4, 'weighted_failure_rate' => 5.1]);
    });

    it('excludes GA4 from the score', function (): void {
        $result = HealthScore::calculate(true, true, true, ['ga4' => [hsStat('purchase', 10, 0, 10)]]);

        expect($result['score'])->toBe(100);
    });

    it('subtracts 40 when the pixel is disabled', function (): void {
        $result = HealthScore::calculate(false, true, false, []);

        expect($result['score'])->toBe(60)
            ->and($result['status'])->toBe('critical')
            ->and($result['deductions'][0]['key'])->toBe('pixel_disabled');
    });

    it('subtracts 20 when there is data but nothing arrived in the last 24 hours', function (): void {
        expect(HealthScore::calculate(true, true, false, [])['score'])->toBe(80)
            ->and(HealthScore::calculate(true, false, false, [])['score'])->toBe(100);
    });

    it('never goes below zero', function (): void {
        $failing = [hsStat('purchase', 10, 0, 10)];
        $result = HealthScore::calculate(false, true, false, ['google_ads' => $failing, 'meta' => $failing]);

        expect($result['score'])->toBe(0);
    });

    it('maps score to status thresholds', function (): void {
        expect(HealthScore::status(90))->toBe('healthy')
            ->and(HealthScore::status(89))->toBe('attention')
            ->and(HealthScore::status(70))->toBe('attention')
            ->and(HealthScore::status(69))->toBe('critical');
    });
});
