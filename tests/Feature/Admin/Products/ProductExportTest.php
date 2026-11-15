<?php

use App\Enums\Status;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\InventoryItem;
use App\Models\StockLocation;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Parse a downloaded workbook back into rows (header first) so tests assert
 * on what an admin would actually see in Excel.
 *
 * @return list<list<mixed>>
 */
function workbookRows(string $contents): array
{
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $contents);

    $reader = new Reader;
    $reader->open($path);

    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    return $rows;
}

function exportVariant(string $brandName, string $modelName, array $variant = [], array $model = []): TyreVariant
{
    $brand = Brand::query()->firstOrCreate(
        ['slug' => str($brandName)->slug()->toString()],
        ['name' => $brandName, 'status' => Status::Active],
    );

    $tyreModel = TyreModel::factory()->create(['brand_id' => $brand->id, 'name' => $modelName, ...$model]);

    return TyreVariant::factory()->create(['tyre_model_id' => $tyreModel->id, ...$variant]);
}

test('the export downloads an xlsx containing only the chosen columns', function () {
    exportVariant('Bridgestone', 'Turanza T005', [
        'sku' => 'BS-1', 'width' => 205, 'profile' => 55, 'rim_diameter' => 16, 'base_price' => 18950,
    ]);

    $response = $this->actingAs(actingSuperAdmin())->get(route('admin.products.export.download', [
        'columns' => ['brand_name', 'sku', 'size', 'base_price'],
    ]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('products-')->toContain('.xlsx');

    $rows = workbookRows($response->streamedContent());

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['Brand', 'SKU', 'Size', 'Base price ($)'])
        ->and($rows[1])->toBe(['Bridgestone', 'BS-1', '205/55R16', 189.5]);
});

test('columns keep their sheet order regardless of the order they were selected in', function () {
    exportVariant('Bridgestone', 'Turanza T005', ['sku' => 'BS-1']);

    $response = $this->actingAs(actingSuperAdmin())->get(route('admin.products.export.download', [
        'columns' => ['sku', 'brand_name'],
    ]));

    expect(workbookRows($response->streamedContent())[0])->toBe(['Brand', 'SKU']);
});

test('filters narrow the exported rows', function (array $filters, array $expectedSkus) {
    $a = exportVariant('Alpha', 'Alpha One', ['sku' => 'A-1', 'base_price' => 10000, 'rim_diameter' => 16, 'status' => Status::Active], ['run_flat' => false]);
    $b = exportVariant('Alpha', 'Alpha Two', ['sku' => 'A-2', 'base_price' => 30000, 'rim_diameter' => 18, 'status' => Status::Draft], ['run_flat' => true]);
    exportVariant('Beta', 'Beta One', ['sku' => 'B-1', 'base_price' => 20000, 'rim_diameter' => 17, 'status' => Status::Active], ['run_flat' => false]);

    $location = StockLocation::factory()->create();
    InventoryItem::factory()->create(['tyre_variant_id' => $a->id, 'stock_location_id' => $location->id, 'qty_on_hand' => 4, 'qty_reserved' => 0]);
    InventoryItem::factory()->create(['tyre_variant_id' => $b->id, 'stock_location_id' => $location->id, 'qty_on_hand' => 2, 'qty_reserved' => 2]);

    $filters['brand_ids'] = isset($filters['brand']) ? [Brand::where('name', $filters['brand'])->value('id')] : null;
    unset($filters['brand']);

    $response = $this->actingAs(actingSuperAdmin())->get(route('admin.products.export.download', array_filter([
        'columns' => ['sku'],
        ...$filters,
    ], fn ($value) => $value !== null)));

    $skus = array_column(array_slice(workbookRows($response->streamedContent()), 1), 0);

    expect($skus)->toEqualCanonicalizing($expectedSkus);
})->with([
    'brand' => [['brand' => 'Beta'], ['B-1']],
    'variant status' => [['statuses' => ['draft']], ['A-2']],
    'rim diameter' => [['rim_diameters' => [16, 17]], ['A-1', 'B-1']],
    'price range in dollars' => [['price_min' => 150, 'price_max' => 250], ['B-1']],
    'run-flat only' => [['run_flat' => 'yes'], ['A-2']],
    'in stock only (available = on hand - reserved)' => [['in_stock_only' => 1], ['A-1']],
    'search by sku' => [['search' => 'B-1'], ['B-1']],
    'search by model name' => [['search' => 'Alpha'], ['A-1', 'A-2']],
]);

test('stock columns sum across every stock location', function () {
    $variant = exportVariant('Alpha', 'Alpha One', ['sku' => 'A-1']);

    foreach ([[5, 1], [3, 0]] as [$onHand, $reserved]) {
        InventoryItem::factory()->create([
            'tyre_variant_id' => $variant->id,
            'stock_location_id' => StockLocation::factory()->create()->id,
            'qty_on_hand' => $onHand,
            'qty_reserved' => $reserved,
        ]);
    }

    $response = $this->actingAs(actingSuperAdmin())->get(route('admin.products.export.download', [
        'columns' => ['sku', 'stock_on_hand', 'stock_reserved', 'stock_available'],
    ]));

    expect(workbookRows($response->streamedContent())[1])->toBe(['A-1', 8, 1, 7]);
});

test('a download with no columns is rejected', function () {
    $this->actingAs(actingSuperAdmin())
        ->get(route('admin.products.export.download'))
        ->assertSessionHasErrors('columns');
});

test('unknown columns and invalid filters are rejected', function () {
    $this->actingAs(actingSuperAdmin())
        ->get(route('admin.products.export.download', ['columns' => ['not_a_column'], 'statuses' => ['bogus']]))
        ->assertSessionHasErrors(['columns.0', 'statuses.0']);
});

test('a download is recorded in the audit log', function () {
    $admin = actingSuperAdmin();
    exportVariant('Alpha', 'Alpha One', ['sku' => 'A-1']);

    $this->actingAs($admin)->get(route('admin.products.export.download', ['columns' => ['sku']]))->assertOk();

    $log = AuditLog::query()->where('action', 'catalog.exported')->sole();

    expect($log->actor_id)->toBe($admin->id)
        ->and($log->after['rows'])->toBe(1)
        ->and($log->after['columns'])->toBe(['sku']);
});

test('the picker page reports how many rows the current filters match', function () {
    exportVariant('Alpha', 'Alpha One', ['sku' => 'A-1']);
    exportVariant('Beta', 'Beta One', ['sku' => 'B-1']);

    $this->actingAs(actingSuperAdmin())
        ->get(route('admin.products.export.index', ['search' => 'Beta']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/export')
            ->where('matchingCount', 1)
            ->where('totalCount', 2)
            ->has('brands', 2)
            ->has('columns'));
});

test('products.view is enough to export, and users without it are denied', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('products.view');

    $this->actingAs($viewer)->get(route('admin.products.export.index'))->assertOk();
    $this->actingAs($viewer)->get(route('admin.products.export.download', ['columns' => ['sku']]))->assertOk();

    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('admin.products.export.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('admin.products.export.download', ['columns' => ['sku']]))->assertForbidden();
});
