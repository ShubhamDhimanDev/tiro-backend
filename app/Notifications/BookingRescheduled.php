<?php

namespace App\Notifications;

use App\Contracts\Notifications\LoggableNotification;
use App\Models\Booking;
use App\Observers\BookingNotificationObserver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dispatched by {@see BookingNotificationObserver} when
 * `scheduled_date` or `slot_start` changes while `status` is `confirmed` —
 * excludes a pre-payment slot change on a still-`pending_hold` booking,
 * see that observer's docblock.
 */
class BookingRescheduled extends Notification implements LoggableNotification, ShouldQueue
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
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Tiro Mobile Tyres booking has been rescheduled')
            ->greeting('Booking updated')
            ->line("Your mobile tyre fitting has been rescheduled to {$this->formattedDate()} between {$this->formattedSlot()}.")
            ->line('If this new time does not work for you, you can reschedule again from your account.')
            ->line("Can't find your booking details? Just reply to this email and we'll help you look it up.");
    }

    public function notificationLogType(): string
    {
        return 'booking.rescheduled';
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
}
