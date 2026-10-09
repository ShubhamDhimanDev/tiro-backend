<?php

use App\Models\Brand;
use App\Models\InventoryItem;
use App\Models\PopularSize;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Database\Seeders\CatalogImportSeeder;
use Database\Seeders\DatabaseSeeder;

/**
 * `db:seed` loads the real catalogue from the client's WooCommerce product
 * export (database/seeders/data) and no demo brands or products.
 */
it('seeds the catalogue from the product sheet and nothing else', function () {
    $this->seed(DatabaseSeeder::class);

    // Bridgestone Ecopia EP150 165/70R13 79S, SKU 11003741 in the sheet.
    $variant = TyreVariant::query()->where('sku', '11003741')->with('tyreModel.brand')->firstOrFail();

    expect($variant->width)->toBe(165)
        ->and($variant->profile)->toBe(70)
        ->and($variant->rim_diameter)->toBe(13)
        ->and($variant->load_index)->toBe('79')
        ->and($variant->speed_rating)->toBe('S')
        ->and($variant->tyreModel->brand->slug)->toBe('bridgestone')
        ->and(Brand::query()->count())->toBeGreaterThan(20)
        ->and(TyreModel::query()->count())->toBeGreaterThan(150)
        ->and(TyreVariant::query()->count())->toBeGreaterThan(600);

    // The importer must not rely on model events: DatabaseSeeder runs under
    // WithoutModelEvents, which suppresses TyreVariant's slug hook.
    expect(TyreVariant::query()->where('slug', '')->orWhereNull('slug')->count())->toBe(0);

    // No demo stock or demo popular sizes (the sheet has neither).
    expect(InventoryItem::query()->count())->toBe(0)
        ->and(PopularSize::query()->count())->toBe(0);
});

it('can be re-run without duplicating anything', function () {
    $this->seed(CatalogImportSeeder::class);

    $counts = [Brand::query()->count(), TyreModel::query()->count(), TyreVariant::query()->count()];

    $this->seed(CatalogImportSeeder::class);

    expect([Brand::query()->count(), TyreModel::query()->count(), TyreVariant::query()->count()])->toBe($counts);
});
