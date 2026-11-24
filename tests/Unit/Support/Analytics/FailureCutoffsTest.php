<?php

declare(strict_types=1);

use App\Support\Analytics\FailureCutoffs;
use Carbon\CarbonImmutable;

describe('FailureCutoffs', function (): void {
    $resolved = CarbonImmutable::parse('2026-11-24 12:00:00');
    $connected = CarbonImmutable::parse('2026-11-24 15:00:00');

    it('uses resolved_at for ordinary codes and ignores the reconnect time', function () use ($resolved, $connected): void {
        $cutoffs = new FailureCutoffs(['google_ads|CONVERSION_PRECEDES_EVENT' => $resolved], ['google_ads' => $connected]);

        expect($cutoffs->cutoff('google_ads', 'CONVERSION_PRECEDES_EVENT')?->toDateTimeString())->toBe('2026-11-24 12:00:00')
            ->and($cutoffs->cutoff('google_ads', 'TOO_RECENT_EVENT'))->toBeNull()
            ->and($cutoffs->ignores('google_ads', 'CONVERSION_PRECEDES_EVENT', '2026-11-24 12:00:00'))->toBeTrue()
            ->and($cutoffs->ignores('google_ads', 'CONVERSION_PRECEDES_EVENT', '2026-11-24 12:00:01'))->toBeFalse();
    });

    it('uses the later of resolved_at and connected_at for auth codes', function () use ($resolved, $connected): void {
        $cutoffs = new FailureCutoffs(['google_ads|GOOGLE_ADS_AUTH' => $resolved], ['google_ads' => $connected, 'meta' => $resolved]);

        expect($cutoffs->cutoff('google_ads', 'GOOGLE_ADS_AUTH')?->toDateTimeString())->toBe('2026-11-24 15:00:00')
            ->and($cutoffs->cutoff('meta', 'META_ACCESS_TOKEN')?->toDateTimeString())->toBe('2026-11-24 12:00:00')
            ->and($cutoffs->cutoff('meta', 'GOOGLE_ADS_AUTH')?->toDateTimeString())->toBe('2026-11-24 12:00:00')
            ->and($cutoffs->latestCutoff('google_ads')?->toDateTimeString())->toBe('2026-11-24 15:00:00')
            ->and($cutoffs->latestCutoff('tiktok'))->toBeNull();
    });

    it('never ignores without a cutoff or a failure time', function (): void {
        $cutoffs = new FailureCutoffs;

        expect($cutoffs->ignores('google_ads', 'GOOGLE_ADS_AUTH', '2020-01-01 00:00:00'))->toBeFalse()
            ->and((new FailureCutoffs(['meta|X' => CarbonImmutable::now()]))->ignores('meta', 'X', null))->toBeFalse();
    });
});
