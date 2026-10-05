<?php

namespace App\Services\Bookings;

use App\Enums\VehicleFitmentPosition;
use App\Models\BookingLineItem;

/**
 * One `items[]` entry from `GET /api/v1/booking-slots` or
 * `POST /api/v1/bookings` — see docs/architecture/02-api-contract.md's
 * "Booking & capacity endpoints" section. Mirrors {@see BookingLineItem}
 * without requiring a persisted row (used before a `Booking` exists at all,
 * e.g. for slot lookup).
 */
final class BookingLineItemInput
{
    public function __construct(
        public readonly int $tyreVariantId,
        public readonly int $quantity,
        public readonly VehicleFitmentPosition $position,
    ) {}
}
