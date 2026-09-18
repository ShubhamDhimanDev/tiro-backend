<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Implements {@see ShouldBeEncrypted}: the plaintext 6-digit `$code` would
 * otherwise sit unencrypted in the `jobs` table while queued (default queue
 * driver is `database`), and indefinitely in `failed_jobs` if delivery ever
 * fails. Laravel encrypts the entire serialized job payload with `APP_KEY`
 * before persisting it and transparently decrypts it for the worker — see
 * docs/architecture/06-open-decisions.md item 14.
 */
class OtpCodeMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Dedicated, high-priority queue for all OTP mail (registration, login,
     * password reset) — isolated from any bulk/marketing/notification mail
     * queue added later, per docs/architecture/08-customer-auth-otp.md §11.
     *
     * Set here (rather than left to the caller to remember `->onQueue()`)
     * so isolation holds no matter how this Mailable is dispatched.
     *
     * A worker must actually be consuming this queue name — with priority
     * ordering, e.g. `queue:work --queue=otp-mail,default` — for OTP mail to
     * be delivered; that's an ops/devops-agent concern, not application code.
     */
    public const QUEUE = 'otp-mail';

    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectForPurpose());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp-code');
    }

    /**
     * Guards actual delivery for `password_reset` OTPs only.
     *
     * `AuthController::requestPasswordReset()` now always creates the
     * challenge row and always queues this mail, regardless of whether the
     * email belongs to an activated Customer — that's what keeps the
     * request endpoint's response time constant either way (see security
     * review, Phase 0 auth, item 3). The "should this email actually get a
     * reset code" decision that used to happen synchronously in the
     * controller happens here instead, inside the queue worker, where it no
     * longer affects response timing.
     *
     * `registration`/`login` purposes are unaffected — they already send
     * unconditionally (login's existence check happens at verify time
     * instead; see AuthController::verifyOtp()).
     */
    public function shouldDeliver(): bool
    {
        if ($this->purpose !== 'password_reset') {
            return true;
        }

        return Customer::query()
            ->where('email', $this->recipientAddress())
            ->whereNotNull('email_verified_at')
            ->exists();
    }

    /**
     * @param  Mailer|Factory  $mailer
     *                                  Untyped to match the parent signature exactly — the queued
     *                                  `SendQueuedMailable` job invokes this with a `Factory`, not a
     *                                  `Mailer`, so a narrower type hint here would break at runtime.
     */
    public function send($mailer): mixed
    {
        if (! $this->shouldDeliver()) {
            return null;
        }

        return parent::send($mailer);
    }

    private function recipientAddress(): ?string
    {
        return $this->to[0]['address'] ?? null;
    }

    private function subjectForPurpose(): string
    {
        return match ($this->purpose) {
            'registration' => 'Verify your email to finish registration',
            'login' => 'Your login code',
            'password_reset' => 'Reset your password',
            default => 'Your verification code',
        };
    }
}
