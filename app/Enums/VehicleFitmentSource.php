<?php

namespace App\Enums;

/**
 * `VehicleFitment.source` — `manual` for admin-entered rows (including rows
 * created via the CSV/JSON importer with no vendor of record), `vendor_feed`
 * reserved for a future automated feed. See docs/architecture/01-data-model.md.
 *
 * Fragile-pattern note: parse raw import/request strings with `::tryFrom()`
 * (never a bare string comparison), and any `match` over this enum in
 * application code must carry an explicit `default => throw` arm — see
 * `RolesAndPermissionsSeeder::permissionNamesForTier()` for the reference
 * pattern and the bug history that made this a standing rule.
 */
enum VehicleFitmentSource: string
{
    case Manual = 'manual';
    case VendorFeed = 'vendor_feed';
}
