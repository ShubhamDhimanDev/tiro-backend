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
 * `Booking.status` transitions to `completed` — reachable via
 * `Admin\Orders\OrderController::updateStatus()`'s `confirmed` ->
 * `completed` transition (see that controller's fix, this phase).
 */
class BookingCompleted extends Notification implements LoggableNotification, ShouldQueue
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
            ->subject('Your tyre fitting is complete')
            ->greeting('All done!')
            ->line('Your mobile tyre fitting has been completed.')
            ->line('Thanks for choosing Tiro Mobile Tyres — we hope to see you again.')
            ->line("Need to find your order or booking details later? Just reply to this email and we'll help you look it up.");
    }

    public function notificationLogType(): string
    {
        return 'booking.completed';
    }

    public function notificationLogSubject(): ?Model
    {
        return $this->booking;
    }
}
