<?php

namespace App\Enums;

use App\Services\Vehicles\FitmentSetValidator;

/**
 * `VehicleFitment.position` — see docs/architecture/01-data-model.md's
 * "Vehicles & fitment" section for the `is_staggered`/`position` agreement
 * rules this enum participates in (enforced by
 * {@see FitmentSetValidator}).
 *
 * Fragile-pattern note: parse raw import/request strings with `::tryFrom()`
 * (never a bare string comparison), and any `match` over this enum in
 * application code must carry an explicit `default => throw` arm — see
 * `RolesAndPermissionsSeeder::permissionNamesForTier()` for the reference
 * pattern and the bug history that made this a standing rule.
 */
enum VehicleFitmentPosition: string
{
    case All = 'all';
    case Front = 'front';
    case Rear = 'rear';
}
