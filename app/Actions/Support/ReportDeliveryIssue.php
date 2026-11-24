<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Enums\Platform;
use App\Mail\SupportReportMail;
use App\Models\PlatformIntegration;
use App\Models\SupportReport;
use App\Models\User;
use App\Support\Analytics\DeliveryErrorCode;
use App\Support\AppVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * "Send to support" from an issue card: stores a report and queues an email to
 * tracking.support_email with the error, counts, breakdown and sample failures.
 *
 * One open report per shop + platform + code. Reporting again returns the open report
 * without another email, unless it is older than 24 hours and new failures happened since
 * (then the same report is refreshed and sent again). Marking the issue resolved closes the
 * report, so a later recurrence can be reported anew.
 */
final class ReportDeliveryIssue
{
    use AsObject;

    public const int RESEND_AFTER_HOURS = 24;

    public function __construct(private readonly CollectIssueDetails $collect) {}

    /**
     * @return array{report: SupportReport, sent: bool}
     */
    public function handle(User $shop, string $platform, string $code, ?string $note = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 2000);
        $end = $now->endOfDay();
        $start = $now->startOfDay()->subDays((int) config('tracking.retention_days', 90) - 1);

        $open = SupportReport::query()->open()
            ->where('user_id', $shop->getKey())->where('platform', $platform)->where('code', $code)
            ->latest('reported_at')->first();

        $details = $this->collect->handle($shop, $platform, $code, $start, $end);

        if ($open !== null && ! $this->shouldResend($open, $details['last_failed_at'], $now)) {
            if ($note !== null && $open->note === null) {
                $open->update(['note' => $note]);
            }

            return ['report' => $open, 'sent' => false];
        }

        $integration = PlatformIntegration::query()->where('user_id', $shop->getKey())->where('platform', $platform)->first();
        $report = $open ?? new SupportReport(['user_id' => $shop->getKey(), 'platform' => $platform, 'code' => $code]);
        $report->fill([
            'platform_integration_id' => $integration?->getKey(),
            'note' => $note ?? $report->note,
            'failure_count' => $details['count'],
            'details' => $details,
            'reported_at' => $now,
        ])->save();

        $recipient = config('tracking.support_email');

        if (! is_string($recipient) || $recipient === '') {
            Log::warning('support.report_not_emailed: SUPPORT_EMAIL is empty', ['support_report_id' => $report->getKey()]);

            return ['report' => $report, 'sent' => false];
        }

        Mail::to($recipient)->queue(new SupportReportMail($this->payload($shop, $report, $integration, $details, $start, $end)));
        $report->update(['emailed_at' => $now]);

        return ['report' => $report, 'sent' => true];
    }

    private function shouldResend(SupportReport $open, ?string $lastFailedAt, CarbonImmutable $now): bool
    {
        if ($open->reported_at->greaterThan($now->subHours(self::RESEND_AFTER_HOURS))) {
            return false;
        }

        return $lastFailedAt !== null && CarbonImmutable::parse($lastFailedAt)->greaterThan($open->reported_at);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function payload(User $shop, SupportReport $report, ?PlatformIntegration $integration, array $details, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ids = array_column($details['samples'], 'delivery_id');
        $idList = $ids === [] ? '0' : implode(', ', $ids);

        return [
            ...$details,
            'report_id' => $report->getKey(),
            'shop_id' => $shop->getKey(),
            'shop_domain' => (string) $shop->name,
            'platform' => $report->platform,
            'platform_label' => match ($report->platform) {
                Platform::GoogleAds->value => 'Google Ads',
                Platform::Meta->value => 'Meta',
                Platform::GoogleAnalytics4->value => 'GA4',
                default => $report->platform,
            },
            'integration_id' => $integration?->getKey(),
            'code' => $report->code,
            'label' => DeliveryErrorCode::label($report->code),
            'note' => $report->note,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'reported_at' => $report->reported_at->toIso8601String(),
            'app_version' => AppVersion::current(),
            'lookup' => [
                'sql' => "SELECT * FROM support_reports WHERE id = {$report->getKey()}; SELECT * FROM platform_deliveries WHERE id IN ({$idList});",
                'tinker' => "php artisan tinker --execute=\"dump(App\\Models\\SupportReport::find({$report->getKey()})->details)\"",
            ],
        ];
    }
}
