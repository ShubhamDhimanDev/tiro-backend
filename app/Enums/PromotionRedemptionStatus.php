<?php

namespace App\Enums;

/**
 * `PromotionRedemption.status` — see
 * docs/architecture/05-promotions-pricing.md's "Promo stock-limit
 * enforcement" section. `Held` mirrors `Booking`'s own `pending_hold`
 * window (`hold_expires_at` always equal to the parent booking's), `Confirmed`
 * is stamped in the same webhook transaction that confirms the booking/order,
 * `Released` is the cascade-release terminal state (TTL expiry or explicit
 * cancel), mirroring `BookingStatus::Expired`/`Cancelled`.
 */
enum PromotionRedemptionStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Released = 'released';
}
