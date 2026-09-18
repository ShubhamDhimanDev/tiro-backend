<?php

use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Enums\TyreType;
use App\Models\Brand;
use App\Models\ServiceZone;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\LocationSeeder;

/**
 * `GET /api/v1/tyres` — see docs/architecture/02-api-contract.md.
 */
beforeEach(function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);
});

it('lists active tyre variants nesting their tyre_model, brand, and category/type', function () {
    $response = $this->getJson('/api/v1/tyres?per_page=100');

    $response->assertOk()->assertJsonStructure([
        'data' => [
            '*' => [
                'id', 'sku', 'slug', 'width', 'profile', 'rim_diameter', 'load_index', 'speed_rating', 'sidewall',
                'tyre_model' => ['id', 'name', 'slug', 'images', 'category', 'tyre_type', 'brand' => ['id', 'name', 'slug', 'logo_path', 'country_of_origin']],
            ],
        ],
        'links',
        'meta',
    ]);

    expect($response->json('data'))->toHaveCount(TyreVariant::query()->count());
    expect($response->json('data.0'))->not->toHaveKey('stock_status');
});

it('filters by non-staggered width/profile/rim_diameter', function () {
    $variant = TyreVariant::query()->where('width', 205)->where('profile', 55)->where('rim_diameter', 16)->firstOrFail();

    $response = $this->getJson('/api/v1/tyres?width=205&profile=55&rim_diameter=16');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($variant->id);
});

it('filters by brand slug', function () {
    $brand = Brand::query()->where('slug', 'michelin')->firstOrFail();

    $response = $this->getJson('/api/v1/tyres?brand=michelin');

    $response->assertOk();
    foreach ($response->json('data') as $item) {
        expect($item['tyre_model']['brand']['id'])->toBe($brand->id);
    }
    expect($response->json('data'))->toHaveCount(
        TyreVariant::query()->whereHas('tyreModel', fn ($q) => $q->where('brand_id', $brand->id))->count(),
    );
});

it('filters by tyre_type and category', function () {
    $response = $this->getJson('/api/v1/tyres?tyre_type=mud_terrain&category=light_truck');

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
    foreach ($response->json('data') as $item) {
        expect($item['tyre_model']['tyre_type'])->toBe(TyreType::MudTerrain->value)
            ->and($item['tyre_model']['category'])->toBe(TyreCategory::LightTruck->value);
    }
});

it('returns a normal 200 with no results for a well-formed but unmatched combination', function () {
    $response = $this->getJson('/api/v1/tyres?width=399&profile=99&rim_diameter=24');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('rejects an out-of-range width with a 422, not a 404', function () {
    $response = $this->getJson('/api/v1/tyres?width=1');

    $response->assertStatus(422)->assertJsonValidationErrors('width');
});

it('rejects an invalid tyre_type enum value with a 422', function () {
    $response = $this->getJson('/api/v1/tyres?tyre_type=not-a-real-type');

    $response->assertStatus(422)->assertJsonValidationErrors('tyre_type');
});

it('requires all paired size fields when staggered=true', function () {
    $response = $this->getJson('/api/v1/tyres?staggered=true&front_width=205&front_profile=55&front_rim_diameter=16');

    $response->assertStatus(422)->assertJsonValidationErrors([
        'rear_width', 'rear_profile', 'rear_rim_diameter',
    ]);
});

it('returns the sanctioned front/rear nested envelope for staggered mode', function () {
    $front = TyreVariant::query()->where('width', 205)->where('profile', 55)->where('rim_diameter', 16)->firstOrFail();
    $rear = TyreVariant::query()->where('width', 245)->where('profile', 35)->where('rim_diameter', 19)->firstOrFail();

    $response = $this->getJson('/api/v1/tyres?staggered=true'
        .'&front_width=205&front_profile=55&front_rim_diameter=16'
        .'&rear_width=245&rear_profile=35&rear_rim_diameter=19');

    $response->assertOk()->assertJsonStructure([
        'data' => [
            'front' => ['data', 'links', 'meta'],
            'rear' => ['data', 'links', 'meta'],
        ],
    ]);

    expect($response->json('data.front.data.0.id'))->toBe($front->id)
        ->and($response->json('data.rear.data.0.id'))->toBe($rear->id);
});

it('attaches zone-scoped stock_status and price when a zone is given, and omits both otherwise', function () {
    $zone = ServiceZone::query()->where('name', 'Melbourne Metro')->firstOrFail();
    $variant = TyreVariant::query()->where('width', 205)->where('profile', 55)->where('rim_diameter', 16)->firstOrFail();

    $withZone = $this->getJson("/api/v1/tyres?width=205&profile=55&rim_diameter=16&zone={$zone->id}");
    $withoutZone = $this->getJson('/api/v1/tyres?width=205&profile=55&rim_diameter=16');

    $withZone->assertOk();
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
    $response = $this->getJson('/api/v1/tyres?width=205&profile=55&rim_diameter=16&zone=999999');

    $response->assertNotFound();
});

it('excludes variants belonging to an inactive tyre model', function () {
    $draftModel = TyreModel::factory()->for(Brand::factory())->create(['status' => Status::Draft]);
    $draftVariant = TyreVariant::factory()->for($draftModel, 'tyreModel')->create(['status' => Status::Active]);

    $response = $this->getJson('/api/v1/tyres?per_page=100');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($draftVariant->id);
});

it('sorts by price ascending and descending', function () {
    $ascending = $this->getJson('/api/v1/tyres?sort=price_asc&per_page=100')->json('data');
    $descending = $this->getJson('/api/v1/tyres?sort=price_desc&per_page=100')->json('data');

    $ascendingPrices = TyreVariant::query()->whereIn('id', collect($ascending)->pluck('id'))->pluck('base_price', 'id');
    $descendingPrices = TyreVariant::query()->whereIn('id', collect($descending)->pluck('id'))->pluck('base_price', 'id');

    $orderedAsc = collect($ascending)->pluck('id')->map(fn ($id) => $ascendingPrices[$id]);
    $orderedDesc = collect($descending)->pluck('id')->map(fn ($id) => $descendingPrices[$id]);

    expect($orderedAsc->values()->all())->toBe($orderedAsc->sort()->values()->all())
        ->and($orderedDesc->values()->all())->toBe($orderedDesc->sortDesc()->values()->all());
});

it('defaults to sorting by the tyre model release date, newest first', function () {
    $response = $this->getJson('/api/v1/tyres?per_page=100');

    $firstItem = $response->json('data.0');

    expect($firstItem['tyre_model']['name'])->toBe('Road Venture MT51');
});
