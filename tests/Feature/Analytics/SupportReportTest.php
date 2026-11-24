<?php

declare(strict_types=1);

use App\Actions\Support\ReportDeliveryIssue;
use App\Enums\Platform;
use App\Enums\TrackingEventType;
use App\Mail\SupportReportMail;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\SupportReport;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Support\Analytics\DeliveryErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

const SUP_PRECEDES = '{"codes":["CONVERSION_PRECEDES_EVENT"],"message":"The conversion precedes the event."}';
const SUP_META_TOKEN = '{"error":{"message":"Error validating access token: Session has expired","type":"OAuthException","code":190}}';

function supIntegration(User $shop, Platform $platform): PlatformIntegration
{
    return PlatformIntegration::query()->firstOrCreate(
        ['user_id' => $shop->getKey(), 'platform' => $platform],
        ['active' => true, 'credentials' => json_encode(['customer_id' => '1234567890']), 'settings' => []],
    );
}

function supDelivery(User $shop, PlatformIntegration $integration, TrackingEventType $type, string $status, string $at, ?string $body = null): PlatformDelivery
{
    $event = TrackingEvent::factory()->forUser($shop)->forEvent($type)->occurredAt($at)->create();

    return PlatformDelivery::query()->forceCreate([
        'tracking_event_id' => $event->getKey(),
        'platform_integration_id' => $integration->getKey(),
        'platform' => $integration->platform->value,
        'status' => $status,
        'attempts' => 1,
        'response_code' => 200,
        'response_body' => $body,
        'sent_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/** Shop "support-shop.myshopify.com" with 6 View Item + 1 Add to Cart CONVERSION_PRECEDES_EVENT failures. */
function supShop(string $domain = 'support-shop.myshopify.com'): array
{
    $shop = User::factory()->create(['name' => $domain, 'pixel_enabled' => true]);
    $ads = supIntegration($shop, Platform::GoogleAds);
    supDelivery($shop, $ads, TrackingEventType::Purchase, 'delivered', '2026-11-24 08:00:00');
    foreach (range(19, 24) as $day) {
        supDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', "2026-11-{$day} 10:00:00", SUP_PRECEDES);
    }
    supDelivery($shop, $ads, TrackingEventType::AddToCart, 'failed', '2026-11-24 11:00:00', SUP_PRECEDES);

    return [$shop, $ads];
}

function supReport(object $test, User $shop, array $payload = []): TestResponse
{
    return $test->withToken($test->shopifySessionToken($shop))->postJson('/api/delivery-issues/support-reports', [
        'platform' => 'google_ads',
        'code' => 'CONVERSION_PRECEDES_EVENT',
        ...$payload,
    ]);
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-24 18:00:00'));
    config(['tracking.support_email' => 'support@example.test', 'tracking.app_version' => 'abc1234']);
    Mail::fake();
});
afterEach(fn () => $this->travelBack());

describe('classification', function (): void {
    it('sends code-side and unknown errors to support, keeps merchant actions primary', function (string $code, bool $support): void {
        expect(DeliveryErrorCode::needsSupport($code))->toBe($support);
    })->with([
        'conversion precedes event' => ['CONVERSION_PRECEDES_EVENT', true],
        'later than maximum date' => ['LATER_THAN_MAXIMUM_DATE', true],
        'unknown' => [DeliveryErrorCode::UNKNOWN, true],
        'generic rejection' => ['SOMETHING_NEW_FROM_GOOGLE', true],
        'google sign-in' => [DeliveryErrorCode::GOOGLE_ADS_AUTH, false],
        'meta token' => [DeliveryErrorCode::META_ACCESS_TOKEN, false],
        'wrong account' => ['EVENT_NOT_FOUND', false],
        'permission' => ['USER_PERMISSION_DENIED', false],
    ]);

    it('exposes support state on overview issues', function (): void {
        [$shop] = supShop();
        $meta = supIntegration($shop, Platform::Meta);
        supDelivery($shop, $meta, TrackingEventType::ViewItem, 'failed', '2026-11-24 12:00:00', SUP_META_TOKEN);

        $issues = collect($this->withToken($this->shopifySessionToken($shop))->getJson('/api/overview?days=7')->assertOk()->json('issues'))->keyBy('code');

        expect($issues['CONVERSION_PRECEDES_EVENT']['support'])->toBe(['primary' => true, 'reported_at' => null])
            ->and($issues['META_ACCESS_TOKEN']['support'])->toBe(['primary' => false, 'reported_at' => null]);
    });
});

describe('POST /api/delivery-issues/support-reports', function (): void {
    it('requires a session token and valid input', function (): void {
        $this->postJson('/api/delivery-issues/support-reports', ['platform' => 'google_ads', 'code' => 'X'])->assertUnauthorized();
        $shop = User::factory()->create();
        supReport($this, $shop, ['platform' => 'tiktok'])->assertUnprocessable();
        supReport($this, $shop, ['code' => 'DROP TABLE;'])->assertUnprocessable();
        supReport($this, $shop, ['note' => str_repeat('a', 2001)])->assertUnprocessable();
        Mail::assertNothingQueued();
    });

    it('stores a report and queues one email with the error details', function (): void {
        [$shop, $ads] = supShop();

        supReport($this, $shop, ['note' => 'Started after the theme update'])
            ->assertCreated()
            ->assertJsonPath('reported_at', '2026-11-24T18:00:00+00:00')
            ->assertJsonPath('sent', true);

        $report = SupportReport::query()->sole();
        expect($report->user_id)->toBe($shop->getKey())
            ->and($report->platform_integration_id)->toBe($ads->getKey())
            ->and($report->failure_count)->toBe(7)
            ->and($report->note)->toBe('Started after the theme update')
            ->and($report->emailed_at?->toIso8601String())->toBe('2026-11-24T18:00:00+00:00');

        Mail::assertQueued(SupportReportMail::class, 1);
        Mail::assertQueued(SupportReportMail::class, function (SupportReportMail $mail) use ($shop, $ads, $report): bool {
            $r = $mail->report;
            expect($mail->hasTo('support@example.test'))->toBeTrue()
                ->and($r['shop_domain'])->toBe('support-shop.myshopify.com')
                ->and($r['shop_id'])->toBe($shop->getKey())
                ->and($r['integration_id'])->toBe($ads->getKey())
                ->and($r['platform_label'])->toBe('Google Ads')
                ->and($r['code'])->toBe('CONVERSION_PRECEDES_EVENT')
                ->and($r['label'])->toBe('Event time is earlier than the ad click')
                ->and($r['count'])->toBe(7)
                ->and($r['first_failed_at'])->toBe('2026-11-19T10:00:00+00:00')
                ->and($r['last_failed_at'])->toBe('2026-11-24T11:00:00+00:00')
                ->and($r['events'])->toBe([
                    ['event' => 'view_item', 'label' => 'View Item', 'count' => 6],
                    ['event' => 'add_to_cart', 'label' => 'Add to Cart', 'count' => 1],
                ])
                ->and($r['samples'])->toHaveCount(5)
                ->and($r['samples'][0])->toHaveKeys(['delivery_id', 'tracking_event_id', 'event', 'occurred_at', 'uploaded_at', 'response_body'])
                ->and($r['samples'][0]['response_body'])->toBe(SUP_PRECEDES)
                ->and($r['app_version'])->toBe('abc1234')
                ->and($r['note'])->toBe('Started after the theme update')
                ->and($r['lookup']['sql'])->toContain("support_reports WHERE id = {$report->getKey()}");

            $html = $mail->render();
            expect($html)->toContain('support-shop.myshopify.com')
                ->toContain('CONVERSION_PRECEDES_EVENT')
                ->toContain('View Item')
                ->toContain('The conversion precedes the event.')
                ->toContain('abc1234')
                ->and($mail->envelope()->subject)->toContain('support-shop.myshopify.com')->toContain('(7 failures)');

            return true;
        });
    });

    it('renders a plain-text version', function (): void {
        [$shop] = supShop();
        supReport($this, $shop)->assertCreated();

        Mail::assertQueued(SupportReportMail::class, function (SupportReportMail $mail): bool {
            [, $text] = (fn () => [null, view($this->content()->text, ['report' => $this->report])->render()])->call($mail);
            expect($text)->toContain('Error: CONVERSION_PRECEDES_EVENT - Event time is earlier than the ad click')
                ->toContain('- View Item (view_item): 6')
                ->toContain('uploaded_at:');

            return true;
        });
    });

    it('does not send another email while a report is open (within 24h)', function (): void {
        [$shop, $ads] = supShop();
        supReport($this, $shop)->assertCreated();

        $this->travelTo(Carbon::parse('2026-11-25 12:00:00'));
        supDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', '2026-11-25 11:00:00', SUP_PRECEDES);
        supReport($this, $shop)->assertOk()->assertJsonPath('sent', false)->assertJsonPath('reported_at', '2026-11-24T18:00:00+00:00');

        expect(SupportReport::query()->count())->toBe(1);
        Mail::assertQueued(SupportReportMail::class, 1);
    });

    it('sends again after 24h only when the error happened again since the report', function (): void {
        [$shop, $ads] = supShop();
        supReport($this, $shop)->assertCreated();

        $this->travelTo(Carbon::parse('2026-11-26 19:00:00'));
        supReport($this, $shop)->assertOk()->assertJsonPath('sent', false);
        Mail::assertQueued(SupportReportMail::class, 1);

        supDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', '2026-11-26 18:30:00', SUP_PRECEDES);
        supReport($this, $shop)->assertOk()->assertJsonPath('sent', true)->assertJsonPath('reported_at', '2026-11-26T19:00:00+00:00');

        expect(SupportReport::query()->count())->toBe(1);
        Mail::assertQueued(SupportReportMail::class, 2);
    });

    it('scopes reports to the current shop', function (): void {
        [$shop] = supShop();
        [$other] = supShop('other-shop.myshopify.com');
        supReport($this, $other)->assertCreated();

        supReport($this, $shop)->assertCreated()->assertJsonPath('sent', true);

        expect(SupportReport::query()->where('user_id', $shop->getKey())->count())->toBe(1);
        Mail::assertQueued(SupportReportMail::class, 2);
        Mail::assertQueued(SupportReportMail::class, fn (SupportReportMail $m): bool => $m->report['shop_domain'] === 'support-shop.myshopify.com' && $m->report['count'] === 7);

        $issues = collect($this->withToken($this->shopifySessionToken($shop))->getJson('/api/overview?days=7')->json('issues'));
        expect($issues->firstWhere('code', 'CONVERSION_PRECEDES_EVENT')['support']['reported_at'])->toBe('2026-11-24T18:00:00+00:00');
    });

    it('stores the report without email when no support address is configured', function (): void {
        config(['tracking.support_email' => '']);
        [$shop] = supShop();

        supReport($this, $shop)->assertCreated()->assertJsonPath('sent', false);

        expect(SupportReport::query()->sole()->emailed_at)->toBeNull();
        Mail::assertNothingQueued();
    });
});

describe('with Mark as resolved', function (): void {
    it('closes the open report; a recurrence can be reported again', function (): void {
        [$shop, $ads] = supShop();
        supReport($this, $shop)->assertCreated();

        $this->travelTo(Carbon::parse('2026-11-24 19:00:00'));
        $this->withToken($this->shopifySessionToken($shop))->postJson('/api/delivery-issues/resolutions', ['platform' => 'google_ads', 'code' => 'CONVERSION_PRECEDES_EVENT'])->assertCreated();
        expect(SupportReport::query()->sole()->closed_at?->toIso8601String())->toBe('2026-11-24T19:00:00+00:00');

        $this->travelTo(Carbon::parse('2026-11-24 20:00:00'));
        supDelivery($shop, $ads, TrackingEventType::ViewItem, 'partial_failure', '2026-11-24 19:30:00', SUP_PRECEDES);
        $issue = collect($this->withToken($this->shopifySessionToken($shop))->getJson('/api/overview?days=7')->json('issues'))->firstWhere('code', 'CONVERSION_PRECEDES_EVENT');
        expect($issue['count'])->toBe(1)->and($issue['support']['reported_at'])->toBeNull();

        supReport($this, $shop)->assertCreated()->assertJsonPath('sent', true);
        Mail::assertQueued(SupportReportMail::class, 2);
        Mail::assertQueued(SupportReportMail::class, fn (SupportReportMail $m): bool => $m->report['count'] === 1);
    });

    it('reopens the report when the resolution is undone', function (): void {
        [$shop] = supShop();
        supReport($this, $shop)->assertCreated();
        $token = $this->shopifySessionToken($shop);

        $this->withToken($token)->postJson('/api/delivery-issues/resolutions', ['platform' => 'google_ads', 'code' => 'CONVERSION_PRECEDES_EVENT'])->assertCreated();
        $this->withToken($token)->deleteJson('/api/delivery-issues/resolutions?platform=google_ads&code=CONVERSION_PRECEDES_EVENT')->assertOk();

        expect(SupportReport::query()->sole()->closed_at)->toBeNull();
    });
});

it('leaves failures of other codes out of the report', function (): void {
    [$shop, $ads] = supShop();
    supDelivery($shop, $ads, TrackingEventType::Purchase, 'failed', '2026-11-24 12:00:00', '{"codes":["LATER_THAN_MAXIMUM_DATE"],"message":"x"}');

    $result = ReportDeliveryIssue::make()->handle($shop, 'google_ads', 'CONVERSION_PRECEDES_EVENT');

    expect($result['report']->failure_count)->toBe(7)
        ->and(collect($result['report']->details['events'])->pluck('event')->all())->not->toContain('purchase');
});
