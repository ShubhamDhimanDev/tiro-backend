<?php

namespace App\Notifications;

use App\Contracts\Notifications\LoggableNotification;
use App\Models\Booking;
use App\Notifications\Messages\SmsMessage;
use App\Observers\BookingNotificationObserver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dispatched by {@see BookingNotificationObserver} when a
 * `Booking.status` transitions `pending_hold` -> `confirmed`.
 */
class BookingConfirmed extends Notification implements LoggableNotification, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking)
    {
        // Dedicated notifications queue — see docs/architecture (Phase 7
        // readiness pass), deliberately separate from `otp-mail` (Phase 0,
        // stays high-priority/auth-only) and bare `default`. `onQueue()`
        // rather than a re-declared `public $queue` property, matching
        // `App\Mail\OtpCodeMail`'s identical convention (and sidestepping
        // phpstan's `missingType.property` rule for an untyped property
        // override).
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'sms'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Tiro Mobile Tyres booking is confirmed')
            ->greeting('Booking confirmed')
            ->line("Your mobile tyre fitting is confirmed for {$this->formattedDate()} between {$this->formattedSlot()}.")
            ->line('Our technician will come to you — no need to visit a store.')
            ->line('If anything about your appointment needs to change, you can reschedule or cancel from your account.')
            ->line("Can't find this email later? Just reply to it and we'll help you look up your booking.");
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            "Tiro Mobile Tyres: your booking is confirmed for {$this->formattedDate()} between {$this->formattedSlot()}. See you then! ".
            "Can't find your booking later? Email {$this->supportEmail()} and we'll help you look it up.",
        );
    }

    public function notificationLogType(): string
    {
        return 'booking.confirmed';
    }

    public function notificationLogSubject(): ?Model
    {
        return $this->booking;
    }

    private function formattedDate(): string
    {
        return $this->booking->scheduled_date->toFormattedDateString();
    }

    private function formattedSlot(): string
    {
        return substr($this->booking->slot_start, 0, 5).' and '.substr($this->booking->slot_end, 0, 5);
    }

    /**
     * Guest-continuity fallback (docs/architecture/06-open-decisions.md
     * item 16): once `Booking.manage_token`'s 15-minute one-time-secret
     * replay window closes, a guest who never registered an account has no
     * way to recover it — this is the low-cost mitigation (a support
     * contact, not a token-reissuance mechanism, which is a separate,
     * bigger piece of work). Reuses the app's existing outbound mail
     * address rather than inventing a new contact channel; every
     * notification already sends from — and, absent an explicit
     * `replyTo()`, defaults mail replies back to — this same address.
     */
    private function supportEmail(): string
    {
        return (string) config('mail.from.address');
    }
}
