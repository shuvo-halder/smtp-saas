<?php

namespace App\Mail;

use App\Models\MailDeliveryRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class TransactionalMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Map of template IDs to Blade views.
     */
    public const TEMPLATE_VIEWS = [
        'account_verification' => 'emails.transactional.account_verification',
        'password_reset'       => 'emails.transactional.password_reset',
        'billing_invoice'      => 'emails.transactional.billing_invoice',
        'security_alert'       => 'emails.transactional.security_alert',
    ];

    public function __construct(
        public MailDeliveryRecord $record
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->record->subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $view = self::TEMPLATE_VIEWS[$this->record->template_id] ?? 'emails.transactional.layout';

        return new Content(
            view: $view,
            with: [
                'record'  => $this->record,
                'payload' => $this->record->payload ?? [],
                'subject' => $this->record->subject,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * Get the message headers.
     * Provides deterministic Message-ID and tracking headers for transport-level MTA deduplication.
     */
    public function headers(): Headers
    {
        $domain = parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?: 'mailsaas.local';

        return new Headers(
            messageId: "{$this->record->uuid}@{$domain}",
            text: [
                'X-Delivery-UUID' => $this->record->uuid,
            ],
        );
    }
}
