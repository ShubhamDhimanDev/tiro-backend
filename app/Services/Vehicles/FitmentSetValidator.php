<?php

namespace App\Services\Vehicles;

use App\Enums\VehicleFitmentPosition;
use App\Models\VehicleFitment;

/**
 * Enforces the `is_staggered`/`position` agreement invariant across one
 * vehicle's full set of {@see VehicleFitment} rows — the single
 * shared implementation the CSV/JSON importer ({@see FitmentImportService})
 * and (in a later round) admin CRUD writes both call, per
 * docs/architecture/01-data-model.md's instruction to enforce this at the
 * write layer, not just by convention, without duplicating the logic.
 *
 * Rules (see the data model doc's "is_staggered/position relationship"
 * note):
 *   - `is_staggered = false` -> exactly one row, `position = all`.
 *   - `is_staggered = true` -> exactly two rows, `position = front` and
 *     `position = rear`.
 *   - Every row in the set must agree on `is_staggered`.
 */
class FitmentSetValidator
{
    /**
     * Validate one vehicle's full set of fitment rows.
     *
     * @param  list<array{position: VehicleFitmentPosition, is_staggered: bool}>  $rows
     * @return list<string> Validation error messages; empty when the set is valid.
     */
    public function validate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $staggeredValues = collect($rows)->pluck('is_staggered')->unique();

        if ($staggeredValues->count() > 1) {
            return ['All fitment rows for the same vehicle must agree on is_staggered.'];
        }

        $isStaggered = $staggeredValues->first();
        $positionValues = collect($rows)
            ->pluck('position')
            ->map(fn (VehicleFitmentPosition $position) => $position->value)
            ->sort()
            ->values()
            ->all();

        if ($isStaggered === false) {
            if ($positionValues !== [VehicleFitmentPosition::All->value]) {
                return ['A non-staggered vehicle (is_staggered=false) must have exactly one fitment row with position=all.'];
            }

            return [];
        }

        if ($positionValues !== [VehicleFitmentPosition::Front->value, VehicleFitmentPosition::Rear->value]) {
            return ['A staggered vehicle (is_staggered=true) must have exactly two fitment rows: position=front and position=rear.'];
        }

        return [];
    }
}
