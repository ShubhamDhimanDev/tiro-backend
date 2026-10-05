<?php

namespace App\Http\Requests\Concerns;

use App\Enums\VehicleFitmentPosition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Shared `items[]`/`addons[]` validation rules for every endpoint that
 * accepts cart contents directly (Phase 3 has no server-side cart — see
 * docs/architecture/02-api-contract.md's "Booking & capacity endpoints"
 * section). `items` may legitimately be empty (e.g. an alignment-only
 * appointment) — only type/shape is validated here, not "at least one
 * item".
 */
trait ValidatesBookingCart
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function cartRules(): array
    {
        return [
            'items' => ['sometimes', 'array'],
            'items.*.tyre_variant_id' => ['required', 'integer', 'exists:tyre_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.position' => ['required', Rule::enum(VehicleFitmentPosition::class)],

            // Only customer-selectable booking_addon keys — never
            // `staggered`, which is derived from `items`, not chosen. See
            // docs/architecture/04-booking-capacity-engine.md.
            'addons' => ['sometimes', 'array'],
            'addons.*' => ['string', Rule::in(['alignment', 'locking_nuts'])],
        ];
    }
}
