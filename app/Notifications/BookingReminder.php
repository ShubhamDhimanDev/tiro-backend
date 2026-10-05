<?php

namespace App\Notifications;

use App\Contracts\Notifications\LoggableNotification;
use App\Models\Booking;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Day-before-appointment reminder — dispatched by
 * `App\Console\Commands\SendBookingRemindersCommand` (a scheduled command,
 * not a `Booking` model-event observer: "one day before" isn't a state
 * change). See that command's docblock for the idempotency mechanism (a
 * `NotificationLog` existence check, not a new `Booking` column).
 */
class BookingReminder extends Notification implements LoggableNotification, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking)
    {
        // Dedicated notifications queue — see `BookingConfirmed`'s docblock.
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
            ->subject('Reminder: your tyre fitting is tomorrow')
            ->greeting('See you tomorrow')
            ->line("This is a reminder that your mobile tyre fitting is scheduled for {$this->formattedDate()} between {$this->formattedSlot()}.")
            ->line('Please make sure your vehicle is accessible at the fitting address.')
            ->line('If anything needs to change, you can reschedule or cancel from your account.')
            ->line("Can't find your original confirmation? Just reply to this email and we'll help you look up your booking.");
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            "Tiro Mobile Tyres reminder: your booking is tomorrow, {$this->formattedDate()}, between {$this->formattedSlot()}. ".
            "Can't find your booking details? Email {$this->supportEmail()} and we'll help you look it up.",
        );
    }

    public function notificationLogType(): string
    {
        return 'booking.reminder';
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
     * Guest-continuity fallback — see `BookingConfirmed::supportEmail()`'s
     * docblock (docs/architecture/06-open-decisions.md item 16).
     */
    private function supportEmail(): string
    {
        return (string) config('mail.from.address');
    }
}
