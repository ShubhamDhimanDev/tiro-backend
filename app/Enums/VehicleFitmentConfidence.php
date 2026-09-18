<?php

namespace App\Enums;

/**
 * `VehicleFitment.confidence` — defaults to `Confirmed` for admin-entered
 * rows (see docs/architecture/01-data-model.md).
 *
 * Fragile-pattern note: parse raw import/request strings with `::tryFrom()`
 * (never a bare string comparison), and any `match` over this enum in
 * application code must carry an explicit `default => throw` arm — see
 * `RolesAndPermissionsSeeder::permissionNamesForTier()` for the reference
 * pattern and the bug history that made this a standing rule.
 */
enum VehicleFitmentConfidence: string
{
    case Confirmed = 'confirmed';
    case Likely = 'likely';
}
