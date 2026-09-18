<?php

use App\Enums\VehicleFitmentPosition;
use App\Models\Vehicle;
use App\Models\VehicleFitment;
use App\Services\Vehicles\FitmentSetValidator;
use Database\Seeders\VehicleSeeder;

/**
 * Dev/test-only seed data for the manual vehicle picker (not a production
 * fitment-table import — see `VehicleSeeder`'s class docblock).
 */
it('seeds a handful of vehicles with generated, unique slugs', function () {
    $this->seed(VehicleSeeder::class);

    expect(Vehicle::query()->count())->toBeGreaterThanOrEqual(5)
        ->and(Vehicle::query()->pluck('slug'))->toHaveCount(Vehicle::query()->pluck('slug')->unique()->count());
});

it('seeds at least one staggered vehicle with a valid front/rear pair', function () {
    $this->seed(VehicleSeeder::class);

    $staggered = Vehicle::query()->whereHas('fitments', fn ($q) => $q->where('is_staggered', true))->firstOrFail();
    $positions = $staggered->fitments->pluck('position')->map(fn (VehicleFitmentPosition $p) => $p->value)->sort()->values()->all();

    expect($positions)->toBe(['front', 'rear']);
});

it('seeds at least one non-staggered vehicle with a single "all" fitment row', function () {
    $this->seed(VehicleSeeder::class);

    $nonStaggered = Vehicle::query()->whereHas('fitments', fn ($q) => $q->where('is_staggered', false))->firstOrFail();

    expect($nonStaggered->fitments)->toHaveCount(1)
        ->and($nonStaggered->fitments->first()->position)->toBe(VehicleFitmentPosition::All);
});

it('seeds two vehicles sharing make/model/series/year but differing body_type, with distinct slugs', function () {
    $this->seed(VehicleSeeder::class);

    $corollas = Vehicle::query()->where('make', 'Toyota')->where('model', 'Corolla')->get();

    expect($corollas)->toHaveCount(2)
        ->and($corollas->pluck('body_type')->sort()->values()->all())->toBe(['hatch', 'sedan'])
        ->and($corollas->pluck('slug')->unique())->toHaveCount(2);
});

it('every seeded fitment row satisfies the shared FitmentSetValidator invariants', function () {
    $this->seed(VehicleSeeder::class);

    $validator = new FitmentSetValidator;

    foreach (Vehicle::query()->with('fitments')->get() as $vehicle) {
        $rows = $vehicle->fitments->map(fn (VehicleFitment $f) => [
            'position' => $f->position, 'is_staggered' => $f->is_staggered,
        ])->all();

        expect($validator->validate($rows))->toBe([]);
    }
});
