<?php

use App\Enums\BookingStatus;
use App\Jobs\ReleaseExpiredBookingHold;
use App\Models\Booking;

/**
 * See docs/architecture/04-booking-capacity-engine.md's "Reservation
 * pattern" section — the delayed half of the "defense in depth" pair (the
 * every-minute sweep is the other half, see ReleaseExpiredHoldsCommandTest).
 */
it('expires a pending_hold booking and clears its hold_expires_at', function () {
    $booking = Booking::factory()->create([
        'status' => BookingStatus::PendingHold,
        'hold_expires_at' => now()->subMinute(),
    ]);

    (new ReleaseExpiredBookingHold($booking->id))->handle();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Expired);
    expect($booking->hold_expires_at)->toBeNull();
});

it('is a no-op for a booking that already left pending_hold (confirmed/cancelled by the other release path)', function () {
    $booking = Booking::factory()->confirmed()->create();

    (new ReleaseExpiredBookingHold($booking->id))->handle();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('is a no-op for a since-deleted booking id', function () {
    (new ReleaseExpiredBookingHold(999999))->handle();
})->throwsNoExceptions();
