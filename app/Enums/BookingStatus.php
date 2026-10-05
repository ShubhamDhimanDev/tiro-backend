<?php

namespace App\Enums;

/**
 * `Booking.status` — see docs/architecture/01-data-model.md's `Booking`
 * section and docs/architecture/04-booking-capacity-engine.md's "Reservation
 * pattern" section. `Expired` is deliberately distinct from `Cancelled` (a
 * timed-out hold is a different business event from an explicit cancel).
 */
enum BookingStatus: string
{
    case PendingHold = 'pending_hold';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Expired = 'expired';

    /**
     * Statuses that occupy a technician/van window for slot-computation and
     * capacity-cap purposes — see 04's "Slot computation" step 2.
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return [self::PendingHold, self::Confirmed, self::InProgress];
    }
}
