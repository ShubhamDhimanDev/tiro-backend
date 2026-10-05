<?php

namespace App\Mail;

use App\Models\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Internal notification for a new storefront enquiry, sent to
 * `config('mail.enquiries_to')`. Queued so the public request never waits on
 * SMTP; `SerializesModels` re-fetches the row by id in the worker, so no
 * customer PII sits in the job payload. Reply-To is the enquirer so staff can
 * answer straight from their inbox; the subject carries only the type and
 * reference (never user-supplied text).
 */
class EnquiryReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Enquiry $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->enquiry->email, $this->enquiry->name)],
            subject: "New {$this->enquiry->type->value} enquiry {$this->enquiry->reference}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.enquiry-received');
    }
}
