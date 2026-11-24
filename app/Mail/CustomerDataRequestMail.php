<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * GDPR customers/data_request result for TrackFlow support to pass on to the merchant.
 */
final class CustomerDataRequestMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int|string>  $orderIds
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public readonly string $shopDomain,
        public readonly array $orderIds,
        public readonly ?string $customerId,
        public readonly ?string $requestId,
        public readonly array $rows,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf('[%s] GDPR data request from %s (%d rows)', config('app.name'), $this->shopDomain, count($this->rows)));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.customer-data-request');
    }
}
