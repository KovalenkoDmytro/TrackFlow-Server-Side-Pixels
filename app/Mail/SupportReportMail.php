<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Send to support" email from an issue card. Queued; carries a plain array snapshot
 * (see ReportDeliveryIssue) so it renders the same even if rows change later.
 */
final class SupportReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(public readonly array $report) {}

    public function envelope(): Envelope
    {
        $app = (string) config('app.name');

        return new Envelope(subject: sprintf(
            '[%s support] %s · %s · %s (%d failures)',
            $app,
            $this->report['shop_domain'],
            $this->report['platform_label'],
            $this->report['code'],
            $this->report['count'],
        ));
    }

    public function content(): Content
    {
        return new Content(html: 'mail.support-report', text: 'mail.support-report-text');
    }
}
