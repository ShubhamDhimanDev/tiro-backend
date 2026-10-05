<?php

use App\Enums\TyreCategory;
use App\Enums\TyreSidewall;
use App\Enums\TyreType;
use App\Models\Brand;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Services\Products\CatalogImportService;

/**
 * {@see CatalogImportService} — the WooCommerce product-export importer
 * behind `php artisan catalog:import` and the admin bulk-upload screen.
 * Narrowly scoped to the three qa-lead findings fixed alongside this file:
 * Tags-column run_flat/tyre_type detection, and hard-errored rows never
 * also reporting a warning.
 */
beforeEach(function () {
    $this->importer = new CatalogImportService;
});

/**
 * Builds one raw WooCommerce product-export row matching
 * {@see CatalogImportService::parseRow()}'s expected column shape.
 * `$attributes` supplies the numbered `Attribute N name`/`Attribute N
 * value(s)` pairs by name (order doesn't matter — matched by name, not
 * slot position).
 *
 * @param  array<string, ?string>  $attributes
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function catalogRow(array $attributes = [], array $overrides = []): array
{
    $attributes = array_merge([
        'product ip' => 'SKU-1',
        'tyre type' => 'PASSENGER',
        'pattern' => 'Altimax GS5',
        'size' => '195/65R15 91V',
        'width' => '195',
        'profile' => '65',
        'diameter' => '15',
        'load index' => '91',
        'speed rating' => 'V',
    ], $attributes);

    $attributePairs = [];
    $i = 1;
    foreach ($attributes as $name => $value) {
        $attributePairs["Attribute {$i} name"] = $name;
        $attributePairs["Attribute {$i} value(s)"] = $value;
        $i++;
    }

    return array_merge([
        'Name' => 'Generic  Altimax Gs5 - SKU-1',
        'Published' => '1',
        'Brands' => 'Generic',
        'Categories' => 'Tyres',
        'Tags' => '',
        'SKU' => '',
        'Sale price' => '',
        'Regular price' => '100.00',
        'Description' => '',
        'Short description' => '',
        'Images' => '',
        'Weight (kg)' => '',
        'GTIN, UPC, EAN, or ISBN' => '',
    ], $attributePairs, $overrides);
}

it('detects a run_flat signal in the Tags column when Categories and Tyre Type carry none', function () {
    // Mirrors the real fixture's Zeta Alventi (ID 172) row: Categories is
    // just the placeholder "Tyres" and Tyre Type is "UHP" — the run-flat
    // signal only exists in Tags.
    $row = catalogRow(
        attributes: ['tyre type' => 'UHP', 'pattern' => 'Alventi'],
        overrides: [
            'Name' => 'Zeta  Alventi - ZETAMS03.023.04',
            'Brands' => 'Zeta',
            'Categories' => 'Tyres',
            'Tags' => '2254517, Runflat',
        ],
    );

    $result = $this->importer->import([$row]);

    expect($result->hasErrors())->toBeFalse();
    $model = TyreModel::query()->where('slug', 'zeta-alventi')->firstOrFail();
    expect($model->run_flat)->toBeTrue();
});

it('still detects a run_flat signal from the Categories column on its own', function () {
    $row = catalogRow(
        attributes: ['tyre type' => 'UHP', 'pattern' => 'Alventi'],
        overrides: [
            'Name' => 'Zeta  Alventi - ZETAMS03.008.06',
            'Brands' => 'Zeta',
            'Categories' => 'Runflat, Tyres',
            'Tags' => '',
        ],
    );

    $result = $this->importer->import([$row]);

    expect($result->hasErrors())->toBeFalse();
    $model = TyreModel::query()->where('slug', 'zeta-alventi')->firstOrFail();
    expect($model->run_flat)->toBeTrue();
});

it('resolves tyre_type from a Tags short code when Categories carries no terrain signal', function () {
    // Mirrors the real fixture's Comforser row, minus the Categories text
    // signal it happens to also carry — isolates the Tags-only path.
    $row = catalogRow(
        attributes: ['tyre type' => '4X4', 'pattern' => 'CF3000 Mud-Terrain'],
        overrides: [
            'Name' => 'Comforser  Cf3000 Mud-terrain - 1658013CF3000',
            'Brands' => 'Comforser',
            'Categories' => 'Tyres',
            'Tags' => '1658013, M/T',
        ],
    );

    $result = $this->importer->import([$row]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->warnings)->toBeEmpty();

    $model = TyreModel::query()->where('slug', 'comforser-cf3000-mud-terrain')->firstOrFail();
    expect($model->tyre_type)->toBe(TyreType::MudTerrain);
});

it('resolves all_terrain from a Tags A/T short code the same way', function () {
    $row = catalogRow(
        attributes: ['tyre type' => 'LIGHT TRUCK', 'pattern' => 'Zivaro AT'],
        overrides: [
            'Name' => 'Zeta Zivaro A/T - ZTAMS03.131.01',
            'Brands' => 'Zeta',
            'Categories' => 'Tyres',
            'Tags' => '2157515, A/T',
        ],
    );

    $result = $this->importer->import([$row]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->warnings)->toBeEmpty();

    $model = TyreModel::query()->where('slug', 'zeta-zivaro-at')->firstOrFail();
    expect($model->tyre_type)->toBe(TyreType::AllTerrain);
});

it('does not surface warnings for a row that also hard-errors', function () {
    // Missing Pattern would normally produce a warning, and the
    // non-numeric Profile is a hard error — the row must appear only in
    // `errors`, never also in `warnings`.
    $row = catalogRow(attributes: ['pattern' => null, 'profile' => 'R']);

    $result = $this->importer->import([$row]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->warnings)->toBeEmpty();

    expect(TyreModel::query()->exists())->toBeFalse();
});

/**
 * Broader regression coverage added by backend-tester alongside the tests
 * above — mirrors `FitmentImportServiceTest`'s breadth (a `validCatalogRow()`
 * helper building a full valid WooCommerce product-export row, overridden
 * per test via `array_merge()`), rather than the `catalogRow()`
 * attributes/overrides helper used by the narrower tests above it.
 */
function validCatalogRow(array $overrides = []): array
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

it('creates a new Brand, TyreModel, and TyreVariant from a single valid row', function () {
    $result = $this->importer->import([validCatalogRow()]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->brandsCreated)->toBe(1)
        ->and($result->modelsCreated)->toBe(1)
        ->and($result->variantsCreated)->toBe(1);

    expect(Brand::query()->count())->toBe(1)
        ->and(TyreModel::query()->count())->toBe(1)
        ->and(TyreVariant::query()->count())->toBe(1);

    $brand = Brand::query()->where('slug', 'zeta')->firstOrFail();
    expect($brand->name)->toBe('Zeta');

    $variant = TyreVariant::query()->firstOrFail();
    expect($variant->sku)->toBe('ZT-ALT-001')
        ->and($variant->base_price)->toBe(15000)
        ->and($variant->sidewall)->toBe(TyreSidewall::Standard);
});

it('matches an existing Brand and TyreModel and updates an existing TyreVariant instead of duplicating it', function () {
    $brand = Brand::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']);
    $model = TyreModel::factory()->for($brand)->create([
        'name' => 'Altimax Gs5', 'slug' => 'zeta-altimax-gs5',
        'category' => TyreCategory::Car, 'tyre_type' => TyreType::Highway,
    ]);
    $variant = TyreVariant::factory()->for($model, 'tyreModel')->create([
        'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '91', 'speed_rating' => 'V', 'base_price' => 10000,
    ]);

    $result = $this->importer->import([validCatalogRow(['Regular price' => '175.00'])]);

    expect($result->brandsCreated)->toBe(0)
        ->and($result->brandsMatched)->toBe(1)
        ->and($result->modelsCreated)->toBe(0)
        ->and($result->modelsUpdated)->toBe(1)
        ->and($result->variantsCreated)->toBe(0)
        ->and($result->variantsUpdated)->toBe(1);

    expect(Brand::query()->count())->toBe(1)
        ->and(TyreModel::query()->count())->toBe(1)
        ->and(TyreVariant::query()->count())->toBe(1);

    expect($variant->refresh()->base_price)->toBe(17500);
});

it('groups two rows sharing the same brand and pattern into one TyreModel with two TyreVariants, using the first row for category/tyre_type', function () {
    $rows = [
        validCatalogRow(),
        validCatalogRow([
            'Attribute 1 value(s)' => 'ZT-ALT-002',
            'Attribute 5 value(s)' => '215',
            'Attribute 2 value(s)' => 'SUV',
            'Categories' => 'All Terrain',
        ]),
    ];

    $result = $this->importer->import($rows);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->modelsCreated)->toBe(1)
        ->and($result->variantsCreated)->toBe(2);

    expect(TyreModel::query()->count())->toBe(1)
        ->and(TyreVariant::query()->count())->toBe(2);

    $model = TyreModel::query()->firstOrFail();
    expect($model->category)->toBe(TyreCategory::Car)
        ->and($model->tyre_type)->toBe(TyreType::Highway);
});

it('OR-merges run_flat across every row in a group even when only the second row carries the signal', function () {
    $rows = [
        validCatalogRow(),
        validCatalogRow([
            'Attribute 1 value(s)' => 'ZT-ALT-002',
            'Attribute 5 value(s)' => '215',
            'Categories' => 'Highway Terrain, Runflat',
        ]),
    ];

    $result = $this->importer->import($rows);

    expect($result->hasErrors())->toBeFalse();

    $model = TyreModel::query()->firstOrFail();
    expect($model->run_flat)->toBeTrue();
});

it('excludes a row with a non-numeric Profile value but still imports the rest of the file', function () {
    $rows = [
        validCatalogRow(['Attribute 6 value(s)' => 'R']),
        validCatalogRow([
            'Brands' => 'Nexen',
            'Attribute 1 value(s)' => 'NX-001',
            'Attribute 3 value(s)' => 'N Blue Eco',
        ]),
    ];

    $result = $this->importer->import($rows);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]['row'])->toBe(1)
        ->and($result->errors[0]['message'])->toContain('Non-numeric Profile value');

    expect(Brand::query()->where('slug', 'zeta')->exists())->toBeFalse();
    expect(Brand::query()->where('slug', 'nexen')->exists())->toBeTrue();
    expect(TyreVariant::query()->count())->toBe(1);
    expect(TyreVariant::query()->where('sku', 'NX-001')->exists())->toBeTrue();
});

it('does not surface a hard-errored row\'s resolved category/tyre-type warnings in the result', function () {
    $result = $this->importer->import([validCatalogRow([
        'Attribute 6 value(s)' => 'R',
        'Attribute 3 value(s)' => '',
    ])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->warnings)->toBe([]);
});

it('reports a per-row error for a missing Name value', function () {
    $result = $this->importer->import([validCatalogRow(['Name' => ''])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Name column is required');
    expect(Brand::query()->exists())->toBeFalse();
});

it('reports a per-row error for a missing Brands value', function () {
    $result = $this->importer->import([validCatalogRow(['Brands' => ''])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Brands column is required');
    expect(Brand::query()->exists())->toBeFalse();
});

it('reports a per-row error when no SKU can be resolved', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 1 value(s)' => ''])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Unable to resolve a SKU');
});

it('reports a per-row error when no price can be resolved', function () {
    $result = $this->importer->import([validCatalogRow(['Sale price' => '', 'Regular price' => ''])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Unable to resolve a price');
});

it('reports a per-row error for a missing Load Index attribute', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 8 value(s)' => ''])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Load Index attribute is missing');
});

it('reports a per-row error for a missing Speed Rating attribute', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 9 value(s)' => ''])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Speed Rating attribute is missing');
});

it('reports a per-row error for a non-numeric Width value', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 5 value(s)' => 'wide'])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Non-numeric Width value');
});

it('reports a per-row error for an out-of-range Diameter value', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 7 value(s)' => '70000'])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('out of range');
});

it('falls back to a "{Brand} Generic" model name and a warning when the Pattern attribute is missing', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 3 value(s)' => ''])]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['message'])->toContain('Pattern attribute absent');

    expect(TyreModel::query()->where('name', 'Zeta Generic')->exists())->toBeTrue();
});

it('defaults category to car with a warning for an unrecognized Tyre Type value', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 2 value(s)' => 'FOOBAR'])]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['message'])->toContain('category defaulted to "car"');

    expect(TyreModel::query()->firstOrFail()->category)->toBe(TyreCategory::Car);
});

it('defaults tyre_type to highway with a warning when Tyre Type/Categories do not resolve to a specific type', function () {
    $result = $this->importer->import([validCatalogRow(['Categories' => 'Passenger Tyres'])]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['message'])->toContain('defaulted to "highway"');

    expect(TyreModel::query()->firstOrFail()->tyre_type)->toBe(TyreType::Highway);
});

it('resolves a Categories value containing "Mud Terrian" (tolerating the source typo) cleanly with zero warnings', function () {
    $result = $this->importer->import([validCatalogRow(['Categories' => 'Mud Terrian'])]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->warnings)->toBe([]);

    expect(TyreModel::query()->firstOrFail()->tyre_type)->toBe(TyreType::MudTerrain);
});

it('skips a row with Published < 1, mentioning it was trashed', function () {
    $result = $this->importer->import([validCatalogRow(['Published' => '0'])]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]['message'])->toContain('trashed');

    expect(Brand::query()->exists())->toBeFalse();
});

it('skips a row with a "(Copy)" name suffix, mentioning it was a duplicate', function () {
    $result = $this->importer->import([validCatalogRow(['Name' => 'Zeta Altimax GS5 (Copy)'])]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]['message'])->toContain('duplicate');

    expect(TyreModel::query()->exists())->toBeFalse();
});

it('skips a row with both Published < 1 and a "(Copy)" name suffix, mentioning both reasons', function () {
    $result = $this->importer->import([validCatalogRow([
        'Published' => '0',
        'Name' => 'Zeta Altimax GS5 (Copy)',
    ])]);

    expect($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]['message'])->toContain('trashed')
        ->and($result->skipped[0]['message'])->toContain('duplicate');
});

it('short-circuits the skip check before any other field validation', function () {
    $result = $this->importer->import([validCatalogRow([
        'Published' => '0',
        'Attribute 6 value(s)' => 'R',
    ])]);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->errors)->toBe([])
        ->and($result->skipped)->toHaveCount(1);

    expect(TyreVariant::query()->exists())->toBeFalse();
});

it('reports a per-row error and leaves no orphan Brand/TyreModel when a SKU already belongs to a different variant', function () {
    $other = TyreVariant::factory()->create(['sku' => 'TAKEN-SKU']);

    $brandCountBefore = Brand::query()->count();
    $modelCountBefore = TyreModel::query()->count();
    $variantCountBefore = TyreVariant::query()->count();

    $result = $this->importer->import([validCatalogRow(['Attribute 1 value(s)' => 'TAKEN-SKU'])]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('already used by a different tyre variant');

    expect(Brand::query()->where('slug', 'zeta')->exists())->toBeFalse();
    expect(Brand::query()->count())->toBe($brandCountBefore);
    expect(TyreModel::query()->count())->toBe($modelCountBefore);
    expect(TyreVariant::query()->count())->toBe($variantCountBefore);
    expect($other->refresh()->sku)->toBe('TAKEN-SKU');
});

it('resolves sidewall to commercial when the rim diameter is immediately followed by "C" in Size', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 4 value(s)' => '195/75R16C'])]);

    expect($result->hasErrors())->toBeFalse();
    expect(TyreVariant::query()->firstOrFail()->sidewall)->toBe(TyreSidewall::Commercial);
});

it('resolves sidewall to xl when load_index ends in "XL"', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 8 value(s)' => '91XL'])]);

    expect($result->hasErrors())->toBeFalse();
    expect(TyreVariant::query()->firstOrFail()->sidewall)->toBe(TyreSidewall::ExtraLoad);
});

it('resolves sidewall to reinforced for a compound load_index with no commercial/XL signal', function () {
    $result = $this->importer->import([validCatalogRow(['Attribute 8 value(s)' => '108/106'])]);

    expect($result->hasErrors())->toBeFalse();
    expect(TyreVariant::query()->firstOrFail()->sidewall)->toBe(TyreSidewall::Reinforced);
});

it('computes full accurate counts but persists nothing in dry-run mode', function () {
    $result = $this->importer->import([validCatalogRow()], dryRun: true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->brandsCreated)->toBe(1)
        ->and($result->modelsCreated)->toBe(1)
        ->and($result->variantsCreated)->toBe(1);

    expect(Brand::query()->count())->toBe(0);
    expect(TyreModel::query()->count())->toBe(0);
    expect(TyreVariant::query()->count())->toBe(0);
});

it('imports from a real CSV file with a header row', function () {
    $path = tempnam(sys_get_temp_dir(), 'catalog_import_').'.csv';
    $row = validCatalogRow();
    $handle = fopen($path, 'w');
    fputcsv($handle, array_keys($row));
    fputcsv($handle, array_values($row));
    fclose($handle);

    try {
        $result = $this->importer->importFromFile($path);

        expect($result->hasErrors())->toBeFalse()
            ->and($result->brandsCreated)->toBe(1);
    } finally {
        unlink($path);
    }
});

it('imports from a real JSON file', function () {
    $path = tempnam(sys_get_temp_dir(), 'catalog_import_').'.json';
    file_put_contents($path, json_encode([validCatalogRow()]));

    try {
        $result = $this->importer->importFromFile($path);

        expect($result->hasErrors())->toBeFalse()
            ->and($result->brandsCreated)->toBe(1);
    } finally {
        unlink($path);
    }
});
