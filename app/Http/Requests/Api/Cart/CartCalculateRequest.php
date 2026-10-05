<?php

namespace App\Http\Requests\Api\Cart;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/cart/calculate` body — two mutually exclusive input modes,
 * see docs/architecture/02-api-contract.md's "Cart, Checkout & Payment
 * endpoints" section:
 *
 * - Mode 1 (pre-booking): `{ zone_id, items: [{ tyre_variant_id, quantity }] }`
 * - Mode 2 (checkout): `{ booking_id }` — zone/items are derived from the
 *   booking, never re-supplied.
 *
 * Mode 1 also accepts optional `promo_code` (string) and `flexible` (bool).
 *
 * Works unauthenticated for mode 1 (matches the zone-aware `/tyres`
 * endpoints' posture); mode 2's ownership check
 * (`AuthorizesBookingAccess::authorizeGuestOrOwner()`) happens in the
 * controller, not here.
 */
class CartCalculateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'booking_id' => ['required_without:zone_id', 'prohibits:zone_id,items', 'integer', 'min:1'],
            'zone_id' => ['required_without:booking_id', 'prohibits:booking_id', 'integer', 'min:1'],
            'items' => ['sometimes', 'array'],
            'items.*.tyre_variant_id' => ['required_with:items', 'integer', 'exists:tyre_variants,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:20'],
            // Mode 1 only: mode 2 takes the code and flexible flag from the
            // booking itself (stored at hold time), so both are prohibited
            // alongside `booking_id`. A wrong/expired code is NOT a 422 — it
            // comes back as `data.promo_error` next to a normally priced cart.
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40', 'prohibits:booking_id'],
            'flexible' => ['sometimes', 'boolean', 'prohibits:booking_id'],
        ];
    }
}
