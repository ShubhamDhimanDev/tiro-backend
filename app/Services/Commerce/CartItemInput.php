<?php

namespace App\Services\Commerce;

/**
 * One `items[]` entry for `POST /api/v1/cart/calculate` mode 1 (raw
 * `{zone_id, items}`, pre-booking) — see
 * docs/architecture/02-api-contract.md's "Cart, Checkout & Payment
 * endpoints" section. Deliberately no `position` field, unlike
 * `App\Services\Bookings\BookingLineItemInput` — `OrderLineItem`/cart
 * pricing is commerce-only, position/fitment detail lives on the booking
 * side, not duplicated here.
 */
final class CartItemInput
{
    public function __construct(
        public readonly int $tyreVariantId,
        public readonly int $quantity,
    ) {}
}
