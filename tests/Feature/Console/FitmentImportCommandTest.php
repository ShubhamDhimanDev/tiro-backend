<?php

use App\Models\Vehicle;
use App\Services\Vehicles\FitmentImportService;

/**
 * `php artisan fitment:import {path} [--dry-run]` — a thin wrapper around
 * {@see FitmentImportService}. See
 * docs/architecture/01-data-model.md.
 */
function writeFitmentCsvFixture(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'fitment_import_cmd_').'.csv';
    $header = ['make', 'model', 'series', 'body_type', 'year_from', 'year_to', 'position', 'width', 'profile',
        'rim_diameter', 'load_index', 'speed_rating', 'is_staggered', 'source', 'confidence', 'notes'];

    $lines = [implode(',', $header)];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($key) => $row[$key] ?? '', $header));
    }

    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

it('imports a valid CSV file and exits successfully', function () {
    $path = writeFitmentCsvFixture([[
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport', 'body_type' => 'sedan',
        'year_from' => 2019, 'year_to' => 2023, 'position' => 'all', 'width' => 205, 'profile' => 55,
        'rim_diameter' => 16, 'load_index' => 91, 'speed_rating' => 'V', 'is_staggered' => 'false',
        'source' => 'manual', 'confidence' => 'confirmed',
    ]]);

    try {
        $this->artisan('fitment:import', ['path' => $path])
            ->assertExitCode(0);

        expect(Vehicle::query()->where('model', 'Corolla')->exists())->toBeTrue();
    } finally {
        unlink($path);
    }
});

it('exits non-zero and persists nothing for a file containing a malformed row', function () {
    $path = writeFitmentCsvFixture([[
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport', 'body_type' => 'sedan',
        'year_from' => 2019, 'year_to' => 2023, 'position' => 'sideways', 'width' => 205, 'profile' => 55,
        'rim_diameter' => 16, 'load_index' => 91, 'speed_rating' => 'V', 'is_staggered' => 'false',
        'source' => 'manual', 'confidence' => 'confirmed',
    ]]);

    try {
        $this->artisan('fitment:import', ['path' => $path])
            ->assertExitCode(1);

        expect(Vehicle::query()->exists())->toBeFalse();
    } finally {
        unlink($path);
    }
});

it('--dry-run persists nothing even for an otherwise-valid file', function () {
    $path = writeFitmentCsvFixture([[
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport', 'body_type' => 'sedan',
        'year_from' => 2019, 'year_to' => 2023, 'position' => 'all', 'width' => 205, 'profile' => 55,
        'rim_diameter' => 16, 'load_index' => 91, 'speed_rating' => 'V', 'is_staggered' => 'false',
        'source' => 'manual', 'confidence' => 'confirmed',
    ]]);

    try {
        $this->artisan('fitment:import', ['path' => $path, '--dry-run' => true])
            ->assertExitCode(0);

        expect(Vehicle::query()->exists())->toBeFalse();
    } finally {
        unlink($path);
    }
});

it('fails fast with a clear error for a nonexistent file', function () {
    $this->artisan('fitment:import', ['path' => '/nonexistent/path/fitment.csv'])
        ->assertExitCode(1);
});
