<?php

namespace App\Mail;

use App\Support\SystemMailFrom;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A message from the landing page's public contact form to the OJT coordinator.
 *
 * Deliberately NOT queued. Nothing in this project implements `ShouldQueue` and
 * deployments run `QUEUE_CONNECTION=sync` with no worker (see PROJECT.md), so
 * queueing it would silently never send.
 */
class CoordinatorContactMessage extends Mailable
{
    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $body,
    ) {}

    /**
     * The FROM address is the system's own, resolved through the same helper
     * the two existing notifications use — NOT the sender's. Putting a visitor's
     * address in From is what SPF and DKIM exist to reject, and it would get the
     * whole domain's mail treated as spoofed.
     *
     * `replyTo` is what actually makes the form useful: the coordinator hits
     * Reply and reaches the person who wrote in.
     */
    public function envelope(): Envelope
    {
        /*
         * `resolve()` returns NULL to mean "no System Email is set, fall back to
         * MAIL_FROM_ADDRESS" — so the null case must leave `from` UNSET and let
         * Laravel apply the global config. Passing the null straight into
         * `new Address()` would throw instead of falling back.
         */
        $systemFrom = SystemMailFrom::resolve();

        return new Envelope(
            from: $systemFrom ? new Address($systemFrom, (string) config('app.name')) : null,
            replyTo: [new Address($this->senderEmail, $this->senderName)],
            subject: 'InternTrack enquiry from '.$this->senderName,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.coordinator-contact');
    }
}
