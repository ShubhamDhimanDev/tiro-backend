<?php

use App\Enums\VehicleFitmentPosition;
use App\Models\Vehicle;
use App\Models\VehicleFitment;
use App\Services\Vehicles\FitmentImportService;
use App\Services\Vehicles\FitmentSetValidator;

/**
 * {@see FitmentImportService} — the single import code path behind
 * `php artisan fitment:import` and the future admin bulk-upload screen. See
 * docs/architecture/01-data-model.md's "In-house fitment table:
 * import/seeding tooling" section.
 */
beforeEach(function () {
    $this->importer = new FitmentImportService(new FitmentSetValidator);
});

function validFitmentRow(array $overrides = []): array
{
    return array_merge([
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport', 'body_type' => 'sedan',
        'year_from' => '2019', 'year_to' => '2023',
        'position' => 'all', 'width' => '205', 'profile' => '55', 'rim_diameter' => '16',
        'load_index' => '91', 'speed_rating' => 'V',
        'is_staggered' => 'false', 'source' => 'manual', 'confidence' => 'confirmed', 'notes' => null,
    ], $overrides);
}

it('creates a new vehicle and its fitment row', function () {
    $result = $this->importer->import([validFitmentRow()]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->vehiclesCreated)->toBe(1)
        ->and($result->vehiclesMatched)->toBe(0)
        ->and($result->fitmentsCreated)->toBe(1)
        ->and($result->fitmentsUpdated)->toBe(0);

    $vehicle = Vehicle::query()->where('make', 'Toyota')->where('model', 'Corolla')->firstOrFail();
    $fitment = $vehicle->fitments()->firstOrFail();
    expect($fitment->width)->toBe(205)
        ->and($fitment->position->value)->toBe('all')
        ->and($fitment->source->value)->toBe('manual');
});

it('matches an existing vehicle by its unique tuple and updates an existing fitment row instead of duplicating it', function () {
    $vehicle = Vehicle::factory()->create([
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport', 'body_type' => 'sedan',
        'year_from' => 2019, 'year_to' => 2023,
    ]);
    VehicleFitment::factory()->for($vehicle)->create(['position' => VehicleFitmentPosition::All, 'width' => 195]);

    $result = $this->importer->import([validFitmentRow(['width' => '215'])]);

    expect($result->vehiclesCreated)->toBe(0)
        ->and($result->vehiclesMatched)->toBe(1)
        ->and($result->fitmentsCreated)->toBe(0)
        ->and($result->fitmentsUpdated)->toBe(1);

    expect(Vehicle::query()->count())->toBe(1);
    expect($vehicle->fitments()->firstOrFail()->width)->toBe(215);
});

it('creates both rows of a staggered pair from two rows sharing the same vehicle key', function () {
    $rows = [
        validFitmentRow(['position' => 'front', 'is_staggered' => 'true', 'width' => '205']),
        validFitmentRow(['position' => 'rear', 'is_staggered' => 'true', 'width' => '245']),
    ];

    $result = $this->importer->import($rows);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->vehiclesCreated)->toBe(1)
        ->and($result->fitmentsCreated)->toBe(2);

    $vehicle = Vehicle::query()->firstOrFail();
    expect($vehicle->fitments()->count())->toBe(2);
});

it('reports a per-row error for an unrecognized position value instead of silently skipping the row, and still imports the rest of the file', function () {
    $rows = [
        validFitmentRow(['position' => 'diagonal']),
        validFitmentRow(['model' => 'Camry']),
    ];

    $result = $this->importer->import($rows);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]['row'])->toBe(1)
        ->and($result->errors[0]['message'])->toContain('Unrecognized position value "diagonal"');

    // The bad row's vehicle was never created...
    expect(Vehicle::query()->where('model', 'Corolla')->exists())->toBeFalse();
    // ...but the good row in the same batch still imported.
    expect(Vehicle::query()->where('model', 'Camry')->exists())->toBeTrue();
    expect($result->vehiclesCreated)->toBe(1);
});

it('reports a per-row error for an unrecognized source value instead of silently skipping the row', function () {
    $result = $this->importer->import([validFitmentRow(['source' => 'dealer-website'])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Unrecognized source value "dealer-website"');
    expect(Vehicle::query()->exists())->toBeFalse();
});

it('reports a per-row error for an unrecognized confidence value instead of silently skipping the row', function () {
    $result = $this->importer->import([validFitmentRow(['confidence' => 'guessing'])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Unrecognized confidence value "guessing"');
});

it('defaults confidence to confirmed when the column is blank', function () {
    $result = $this->importer->import([validFitmentRow(['confidence' => null])]);

    expect($result->hasErrors())->toBeFalse();
    expect(VehicleFitment::query()->firstOrFail()->confidence->value)->toBe('confirmed');
});

it('reports a per-row error for a missing required field', function () {
    $result = $this->importer->import([validFitmentRow(['make' => ''])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('make field is required');
});

it('reports a per-row error for a non-numeric size field', function () {
    $result = $this->importer->import([validFitmentRow(['width' => 'wide'])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('width field must be a positive whole number');
});

it('reports a group-level error, attributed to every row in the group, when is_staggered disagrees within one vehicle', function () {
    $rows = [
        validFitmentRow(['position' => 'front', 'is_staggered' => 'true']),
        validFitmentRow(['position' => 'rear', 'is_staggered' => 'false']),
    ];

    $result = $this->importer->import($rows);

    expect($result->errors)->toHaveCount(2);
    expect(Vehicle::query()->exists())->toBeFalse();
});

it('rejects position=all combined with is_staggered=true at the write layer', function () {
    $result = $this->importer->import([validFitmentRow(['position' => 'all', 'is_staggered' => 'true'])]);

    expect($result->hasErrors())->toBeTrue();
    expect(Vehicle::query()->exists())->toBeFalse();
});

it('computes results but persists nothing in dry-run mode', function () {
    $result = $this->importer->import([validFitmentRow()], dryRun: true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->vehiclesCreated)->toBe(1)
        ->and($result->fitmentsCreated)->toBe(1);

    expect(Vehicle::query()->count())->toBe(0);
    expect(VehicleFitment::query()->count())->toBe(0);
});

it('imports from a real CSV file with a header row', function () {
    $path = tempnam(sys_get_temp_dir(), 'fitment_import_').'.csv';
    $header = implode(',', array_keys(validFitmentRow()));
    $row = validFitmentRow();
    $line = implode(',', array_map(fn ($v) => $v ?? '', $row));
    file_put_contents($path, "{$header}\n{$line}\n");

    try {
        $result = $this->importer->importFromFile($path);

        expect($result->hasErrors())->toBeFalse()
            ->and($result->vehiclesCreated)->toBe(1);
    } finally {
        unlink($path);
    }
});

it('imports from a real JSON file', function () {
    $path = tempnam(sys_get_temp_dir(), 'fitment_import_').'.json';
    file_put_contents($path, json_encode([validFitmentRow()]));

    try {
        $result = $this->importer->importFromFile($path);

        expect($result->hasErrors())->toBeFalse()
            ->and($result->vehiclesCreated)->toBe(1);
    } finally {
        unlink($path);
    }
});
