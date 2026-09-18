<?php

use App\Models\Brand;
use App\Models\InventoryItem;
use App\Models\PopularSize;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\LocationSeeder;

/**
 * Deliberately small, dev/test-only catalogue seed data — enough
 * brand/type/size variety to exercise search later (see
 * docs/architecture/01-data-model.md's Catalogue section).
 */
it('seeds brands, models with 2-3 sizes each, and unique variant slugs/skus', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);

    expect(Brand::query()->count())->toBeGreaterThanOrEqual(2)
        ->and(TyreModel::query()->count())->toBeGreaterThanOrEqual(4);

    foreach (TyreModel::query()->withCount('tyreVariants')->get() as $tyreModel) {
        expect($tyreModel->tyre_variants_count)->toBeGreaterThanOrEqual(2)
            ->toBeLessThanOrEqual(3);
    }

    $variants = TyreVariant::query()->get();
    expect($variants->pluck('slug')->unique())->toHaveCount($variants->count())
        ->and($variants->pluck('sku')->unique())->toHaveCount($variants->count());
});

it('stocks every seeded variant with inventory at the seeded stock locations', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);

    foreach (TyreVariant::query()->get() as $variant) {
        expect(InventoryItem::query()->where('tyre_variant_id', $variant->id)->count())->toBeGreaterThan(0);
    }
});

it('seeds popular sizes that match real seeded variant sizes', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);

    expect(PopularSize::query()->count())->toBeGreaterThanOrEqual(2);

    foreach (PopularSize::query()->get() as $popularSize) {
        $matchesRealVariant = TyreVariant::query()
            ->where('width', $popularSize->width)
            ->where('profile', $popularSize->profile)
            ->where('rim_diameter', $popularSize->rim_diameter)
            ->exists();

        expect($matchesRealVariant)->toBeTrue();
    }
});
