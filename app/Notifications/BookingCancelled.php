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
 * Dispatched by {@see BookingNotificationObserver} when a
 * `Booking.status` transitions to `cancelled`.
 */
class BookingCancelled extends Notification implements LoggableNotification, ShouldQueue
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
            ->subject('Your Tiro Mobile Tyres booking has been cancelled')
            ->greeting('Booking cancelled')
            ->line("Your mobile tyre fitting scheduled for {$this->formattedDate()} has been cancelled.")
            ->line('If this was a mistake, or you would like to book another appointment, you can do so any time from your account.')
            ->line("Can't find your booking details? Just reply to this email and we'll help you look it up.");
    }

    public function notificationLogType(): string
    {
        return 'booking.cancelled';
    }

    public function notificationLogSubject(): ?Model
    {
        return $this->booking;
    }

    private function formattedDate(): string
    {
        return $this->booking->scheduled_date->toFormattedDateString();
    }
}
