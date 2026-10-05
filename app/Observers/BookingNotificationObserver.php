<?php

namespace App\Observers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingCompleted;
use App\Notifications\BookingConfirmed;
use App\Notifications\BookingReminder;
use App\Notifications\BookingRescheduled;

/**
 * Dispatches the state-change-triggered booking notifications (everything
 * except {@see BookingReminder}, a time-based trigger —
 * see `App\Console\Commands\SendBookingRemindersCommand` for that one
 * instead) — see docs/architecture (Phase 7 readiness pass). Registered on
 * `Booking`'s `updated` event in
 * `AppServiceProvider::configureObservers()`, same registration point as
 * `FrontendRevalidationObserver` et al.
 */
class BookingNotificationObserver
{
    public function updated(Booking $booking): void
    {
        $customer = $this->resolveCustomer($booking);

        if ($customer === null) {
            return;
        }

        if ($this->transitionedTo($booking, BookingStatus::Confirmed) && $booking->getOriginal('status') === BookingStatus::PendingHold) {
            $customer->notify(new BookingConfirmed($booking));
        }

        if ($this->transitionedTo($booking, BookingStatus::Cancelled)) {
            $customer->notify(new BookingCancelled($booking));
        }

        if ($this->transitionedTo($booking, BookingStatus::Completed)) {
            $customer->notify(new BookingCompleted($booking));
        }

        // Excludes a pre-payment slot change on a still-`pending_hold`
        // booking (see docs/architecture/04-booking-capacity-engine.md's
        // reschedule-vs-hold distinction) — only a date/slot change on an
        // already-`confirmed` booking counts as a customer-facing
        // reschedule.
        if ($booking->status === BookingStatus::Confirmed && ($booking->wasChanged('scheduled_date') || $booking->wasChanged('slot_start'))) {
            $customer->notify(new BookingRescheduled($booking));
        }
    }

    private function transitionedTo(Booking $booking, BookingStatus $status): bool
    {
        return $booking->wasChanged('status') && $booking->status === $status;
    }

    /**
     * A guest booking's `Booking.customer_id` is never backfilled (only
     * `Order.customer_id`/`Address.customer_id` are, at checkout — see
     * `OrderController::store()`'s docblock) — fall back to the linked
     * order's customer so a guest checkout still gets notified at the same
     * email/mobile checkout captured.
     */
    private function resolveCustomer(Booking $booking): ?Customer
    {
        return $booking->customer ?? $booking->order?->customer;
    }
}
