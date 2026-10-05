<?php

namespace App\Enums;

/**
 * `Technician.employment_type` — small closed vocabulary, same convention as
 * {@see VehicleFitmentPosition}: parse raw input with `::tryFrom()`, never a
 * bare string comparison, and any `match` over this enum must carry an
 * explicit `default => throw` arm.
 */
enum TechnicianEmploymentType: string
{
    case Employee = 'employee';
    case Contractor = 'contractor';
}
