<?php

use App\Enums\CatalogImportFormat;
use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Enums\TyreSidewall;
use App\Enums\TyreType;
use App\Models\AuditLog;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Services\Products\CatalogImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;

/**
 * {@see CatalogImportFormat::Simple} — the flat one-row-per-size layout
 * (Brand, Model, Category, Tyre type, SKU, Size, Load index, Speed rating,
 * Base price ($), Status) that the Export screen produces by default.
 */

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function simpleRow(array $overrides = []): array
{
    return array_merge([
        'Brand' => 'Bridgestone',
        'Model' => 'Dueler A/T D697',
        'Category' => '4x4',
        'Tyre type' => 'All terrain',
        'SKU' => 'BS-1',
        'Size' => '225/75R15',
        'Load index' => '110',
        'Speed rating' => 'S',
        'Base price ($)' => '259.01',
        'Status' => 'Active',
    ], $overrides);
}

/**
 * @param  list<array<string, string>>  $rows
 */
function simpleCsv(array $rows, string $name = 'prices.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'simple');
    $handle = fopen($path, 'w');
    fputcsv($handle, array_keys(simpleRow()));
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

beforeEach(function () {
    $this->importer = new CatalogImportService;
});

it('imports a simple-format row into brand, model and variant', function () {
    $result = $this->importer->import([simpleRow()], format: CatalogImportFormat::Simple);

    expect($result->errors)->toBe([])->and($result->warnings)->toBe([]);

    $model = TyreModel::query()->where('slug', 'bridgestone-dueler-a-t-d697')->firstOrFail();

    // Names are taken as written, not re-cased ("A/T" must not become "A/t").
    expect($model->name)->toBe('Dueler A/T D697')
        ->and($model->category)->toBe(TyreCategory::FourByFour)
        ->and($model->tyre_type)->toBe(TyreType::AllTerrain);

    $variant = TyreVariant::query()->where('sku', 'BS-1')->firstOrFail();

    expect($variant->width)->toBe(225)
        ->and($variant->profile)->toBe(75)
        ->and($variant->rim_diameter)->toBe(15)
        ->and($variant->load_index)->toBe('110')
        ->and($variant->speed_rating)->toBe('S')
        ->and($variant->base_price)->toBe(25901)
        ->and($variant->sidewall)->toBe(TyreSidewall::Standard)
        ->and($variant->status)->toBe(Status::Active);
});

it('ignores fully empty rows and keeps the file row numbers on errors', function () {
    $blank = array_fill_keys(array_keys(simpleRow()), '');

    $result = $this->importer->import([
        ['_row' => 1] + simpleRow(),
        ['_row' => 2] + $blank,
        ['_row' => 3] + $blank,
        ['_row' => 4] + simpleRow(['SKU' => 'BS-2', 'Size' => 'nonsense']),
    ], format: CatalogImportFormat::Simple);

    expect($result->rowsProcessed)->toBe(2)
        ->and($result->skipped)->toBe([])
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]['row'])->toBe(4)
        ->and($result->errors[0]['message'])->toContain('nonsense');

    expect(TyreVariant::query()->count())->toBe(1);
});

it('derives sidewall from the size and load index', function () {
    $this->importer->import([
        simpleRow(['SKU' => 'C-1', 'Size' => '195/65R16c', 'Load index' => '107/105']),
        simpleRow(['SKU' => 'X-1', 'Size' => '255/40R19', 'Load index' => '100XL']),
        simpleRow(['SKU' => 'R-1', 'Size' => '215/75R16', 'Load index' => '113/111']),
    ], format: CatalogImportFormat::Simple);

    expect(TyreVariant::query()->where('sku', 'C-1')->value('sidewall'))->toBe(TyreSidewall::Commercial)
        ->and(TyreVariant::query()->where('sku', 'X-1')->value('sidewall'))->toBe(TyreSidewall::ExtraLoad)
        ->and(TyreVariant::query()->where('sku', 'R-1')->value('sidewall'))->toBe(TyreSidewall::Reinforced);
});

it('keeps a comma-joined SKU exactly as written', function () {
    $this->importer->import([simpleRow(['SKU' => 'BUD0042, ZTAMS325'])], format: CatalogImportFormat::Simple);

    expect(TyreVariant::query()->value('sku'))->toBe('BUD0042, ZTAMS325');
});

it('updates the price of an existing variant without wiping its EAN or weight', function () {
    $this->importer->import([simpleRow()], format: CatalogImportFormat::Simple);

    TyreVariant::query()->update(['ean' => '9300000000017', 'weight_kg' => 11.5]);

    $result = $this->importer->import([simpleRow(['Base price ($)' => '$300.50'])], format: CatalogImportFormat::Simple);

    expect($result->variantsCreated)->toBe(0)->and($result->variantsUpdated)->toBe(1);

    $variant = TyreVariant::query()->sole();

    expect($variant->base_price)->toBe(30050)
        ->and($variant->ean)->toBe('9300000000017')
        ->and((float) $variant->weight_kg)->toBe(11.5);
});

it('honours the Status column and warns on values it does not recognise', function () {
    $result = $this->importer->import([
        simpleRow(['SKU' => 'D-1', 'Status' => 'Draft']),
        simpleRow(['SKU' => 'D-2', 'Size' => '235/75R15', 'Status' => 'Live']),
    ], format: CatalogImportFormat::Simple);

    expect(TyreVariant::query()->where('sku', 'D-1')->value('status'))->toBe(Status::Draft)
        ->and(TyreVariant::query()->where('sku', 'D-2')->value('status'))->toBe(Status::Active)
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['message'])->toContain('Live');
});

it('warns about unrecognised category/tyre type and implausible load indexes but still imports', function () {
    $result = $this->importer->import([
        simpleRow(['Category' => 'Tractor', 'Tyre type' => 'Slick', 'Load index' => '1 1/51']),
        simpleRow(['SKU' => 'BS-2', 'Size' => '225/70R16', 'Load index' => '16']),
    ], format: CatalogImportFormat::Simple);

    expect($result->errors)->toBe([])
        ->and($result->warnings)->toHaveCount(4)
        ->and(TyreVariant::query()->count())->toBe(2)
        ->and(TyreModel::query()->sole()->category)->toBe(TyreCategory::Car);
});

it('rejects rows with a bad price, missing SKU or missing brand without stopping the rest', function () {
    $result = $this->importer->import([
        simpleRow(['SKU' => 'OK-1']),
        simpleRow(['SKU' => 'P-1', 'Size' => '235/75R15', 'Base price ($)' => 'free']),
        simpleRow(['SKU' => '', 'Size' => '245/70R16']),
        simpleRow(['SKU' => 'B-1', 'Size' => '255/70R16', 'Brand' => '']),
    ], format: CatalogImportFormat::Simple);

    expect($result->errors)->toHaveCount(3)
        ->and(TyreVariant::query()->pluck('sku')->all())->toBe(['OK-1']);
});

it('reports which required columns a file is missing', function () {
    $service = new CatalogImportService;

    expect($service->missingSimpleColumns(simpleRow()))->toBe([])
        ->and($service->missingSimpleColumns(['Name' => 'x', 'Brands' => 'y']))
        ->toContain('Brand', 'Model', 'SKU', 'Base price ($)');
});

describe('upload', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
    });

    it('imports a simple-format file chosen via the format option and records it in the audit log', function () {
        $blank = array_fill_keys(array_keys(simpleRow()), '');

        $this->actingAs(actingSuperAdmin())
            ->post(route('admin.products.import.store'), [
                'file' => simpleCsv([simpleRow(), $blank, simpleRow(['SKU' => 'BS-2', 'Size' => '235/75R15'])]),
                'format' => 'simple',
            ])
            ->assertOk();

        expect(TyreVariant::query()->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'catalog.imported')->sole()->after['format'])->toBe('simple');
    });

    it('refuses a file whose columns do not match the chosen format', function () {
        $path = tempnam(sys_get_temp_dir(), 'woo');
        file_put_contents($path, "Name,Published,Brands\nZeta Alventi - ZT1,1,ZETA\n");

        $this->actingAs(actingSuperAdmin())
            ->post(route('admin.products.import.store'), [
                'file' => new UploadedFile($path, 'woo.csv', 'text/csv', null, true),
                'format' => 'simple',
            ])
            ->assertSessionHasErrors('file');

        expect(TyreVariant::query()->count())->toBe(0);
    });

    it('rejects an unknown format value', function () {
        $this->actingAs(actingSuperAdmin())
            ->post(route('admin.products.import.store'), [
                'file' => simpleCsv([simpleRow()]),
                'format' => 'excel',
            ])
            ->assertSessionHasErrors('format');
    });
});
