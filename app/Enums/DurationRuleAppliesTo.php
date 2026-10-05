<?php

namespace App\Enums;

/**
 * `DurationRule.applies_to` — see docs/architecture/01-data-model.md's
 * `DurationRule` section for the full `(applies_to, key)` vocabulary table
 * and docs/architecture/04-booking-capacity-engine.md for the formula that
 * consumes it. Fragile-pattern note applies (see
 * {@see VehicleFitmentPosition}): parse with `::tryFrom()`, never a bare
 * string comparison, and any `match` over this enum must carry an explicit
 * `default => throw` arm.
 */
enum DurationRuleAppliesTo: string
{
    /** Exactly one row (`key = setup_overhead`), applied once per booking. */
    case Base = 'base';

    /** Per-tyre-unit minutes, keyed by `App\Enums\TyreCategory` value. */
    case TyreCategory = 'tyre_category';

    /** Per-tyre-unit minutes, applied only to run-flat line items (`key = run_flat`). */
    case TyreAddon = 'tyre_addon';

    /** Once-per-booking minutes (`alignment`, `locking_nuts`, `staggered`). */
    case BookingAddon = 'booking_addon';
}
