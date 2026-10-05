<?php

use App\Models\Brand;
use App\Models\TyreVariant;
use App\Services\Products\CatalogImportService;

/**
 * `php artisan catalog:import {path} [--dry-run]` — a thin wrapper around
 * {@see CatalogImportService}. Mirrors
 * `tests/Feature/Console/FitmentImportCommandTest.php`'s shape, with one
 * deliberate structural difference: `CatalogImportService` commits every
 * valid row in a batch even when other rows in the same file hard-error (see
 * that service's class docblock), so — unlike Fitment's equivalent test —
 * the malformed-row test here asserts the good rows in the file WERE
 * persisted, not that nothing was.
 */
function catalogCsvRow(array $overrides = []): array
{
    return array_merge([
        'ID' => '101',
        'Name' => 'Zeta Altimax GS5',
        'Published' => '1',
        'Description' => 'A great tyre for daily driving.',
        'Short description' => 'Great tyre.',
        'Sale price' => '',
        'Regular price' => '150.00',
        'Categories' => 'Highway Terrain',
        'Images' => 'https://example.com/img1.jpg',
        'Brands' => 'Zeta',
        'GTIN, UPC, EAN, or ISBN' => '1234567890123',
        'Weight (kg)' => '9.5',
        'Attribute 1 name' => 'Product IP',
        'Attribute 1 value(s)' => 'ZT-ALT-001',
        'Attribute 2 name' => 'Tyre Type',
        'Attribute 2 value(s)' => 'PASSENGER',
        'Attribute 3 name' => 'Pattern',
        'Attribute 3 value(s)' => 'Altimax GS5',
        'Attribute 4 name' => 'Size',
        'Attribute 4 value(s)' => '205/55R16',
        'Attribute 5 name' => 'Width',
        'Attribute 5 value(s)' => '205',
        'Attribute 6 name' => 'Profile',
        'Attribute 6 value(s)' => '55',
        'Attribute 7 name' => 'Diameter',
        'Attribute 7 value(s)' => '16',
        'Attribute 8 name' => 'Load Index',
        'Attribute 8 value(s)' => '91',
        'Attribute 9 name' => 'Speed Rating',
        'Attribute 9 value(s)' => 'V',
    ], $overrides);
}

function writeCatalogCsvFixture(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'catalog_import_cmd_').'.csv';
    $handle = fopen($path, 'w');
    $header = array_keys(catalogCsvRow());

    fputcsv($handle, $header);
    foreach ($rows as $row) {
        fputcsv($handle, array_map(fn ($key) => $row[$key] ?? '', $header));
    }

    fclose($handle);

    return $path;
}

it('imports a valid CSV file and exits successfully', function () {
    $path = writeCatalogCsvFixture([catalogCsvRow()]);

    try {
        $this->artisan('catalog:import', ['path' => $path])
            ->assertExitCode(0);

        expect(TyreVariant::query()->where('sku', 'ZT-ALT-001')->exists())->toBeTrue();
    } finally {
        unlink($path);
    }
});

it('exits non-zero for a file containing a hard-error row, but still persists the other valid rows in the file', function () {
    $path = writeCatalogCsvFixture([
        catalogCsvRow(['Attribute 6 value(s)' => 'R']),
        catalogCsvRow([
            'Brands' => 'Nexen',
            'Attribute 1 value(s)' => 'NX-001',
            'Attribute 3 value(s)' => 'N Blue Eco',
        ]),
    ]);

    try {
        $this->artisan('catalog:import', ['path' => $path])
            ->assertExitCode(1);

        expect(Brand::query()->where('slug', 'zeta')->exists())->toBeFalse();
        expect(TyreVariant::query()->where('sku', 'NX-001')->exists())->toBeTrue();
    } finally {
        unlink($path);
    }
});

it('--dry-run persists nothing even for an otherwise-valid file', function () {
    $path = writeCatalogCsvFixture([catalogCsvRow()]);

    try {
        $this->artisan('catalog:import', ['path' => $path, '--dry-run' => true])
            ->assertExitCode(0);

        expect(Brand::query()->exists())->toBeFalse();
        expect(TyreVariant::query()->exists())->toBeFalse();
    } finally {
        unlink($path);
    }
});

it('fails fast with a clear error for a nonexistent file', function () {
    $this->artisan('catalog:import', ['path' => '/nonexistent/path/catalog.csv'])
        ->assertExitCode(1);
});
