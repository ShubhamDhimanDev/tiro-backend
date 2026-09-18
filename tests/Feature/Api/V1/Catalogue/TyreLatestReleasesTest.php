<?php

use App\Enums\Status;
use App\Models\Brand;
use App\Models\ServiceZone;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\LocationSeeder;

/**
 * `GET /api/v1/tyres/latest-releases` — see docs/architecture/02-api-contract.md.
 */
beforeEach(function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);
});

it('sorts by tyre_model.released_at descending', function () {
    $response = $this->getJson('/api/v1/tyres/latest-releases?per_page=100');

    $response->assertOk();

    $releasedAtByVariantId = TyreVariant::query()->with('tyreModel')->get()
        ->mapWithKeys(fn (TyreVariant $variant) => [$variant->id => $variant->tyreModel->released_at]);

    $returnedOrder = collect($response->json('data'))->pluck('id')
        ->map(fn (int $id) => $releasedAtByVariantId[$id]->timestamp);

    expect($returnedOrder->values()->all())->toBe($returnedOrder->sortDesc()->values()->all());
    expect($response->json('data.0.tyre_model.name'))->toBe('Road Venture MT51');
});

it('excludes variants belonging to an inactive tyre model', function () {
    $draftModel = TyreModel::factory()->for(Brand::factory())->create([
        'status' => Status::Draft,
        'released_at' => now(),
    ]);
    $draftVariant = TyreVariant::factory()->for($draftModel, 'tyreModel')->create(['status' => Status::Active]);

    $response = $this->getJson('/api/v1/tyres/latest-releases?per_page=100');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($draftVariant->id);
});

it('attaches zone-scoped stock_status and price when a zone is given, and omits both otherwise', function () {
    $zone = ServiceZone::query()->where('name', 'Melbourne Metro')->firstOrFail();

    $withZone = $this->getJson("/api/v1/tyres/latest-releases?zone={$zone->id}&per_page=1");
    $withoutZone = $this->getJson('/api/v1/tyres/latest-releases?per_page=1');

    $withZone->assertOk();
    $variant = TyreVariant::query()->findOrFail($withZone->json('data.0.id'));
    expect($withZone->json('data.0.stock_status'))->toBe('in_stock')
        ->and($withZone->json('data.0.unit_price'))->toBe($variant->base_price)
        ->and($withZone->json('data.0.promotional_price'))->toBeNull()
        ->and($withZone->json('data.0.currency'))->toBe('AUD');

    $withoutZone->assertOk();
    expect($withoutZone->json('data.0'))->not->toHaveKey('stock_status')
        ->and($withoutZone->json('data.0'))->not->toHaveKey('unit_price')
        ->and($withoutZone->json('data.0'))->not->toHaveKey('promotional_price')
        ->and($withoutZone->json('data.0'))->not->toHaveKey('currency');
});

it('returns 404 for an unresolvable zone id instead of degrading to an unfiltered result', function () {
    $response = $this->getJson('/api/v1/tyres/latest-releases?zone=999999');

    $response->assertNotFound();
});
