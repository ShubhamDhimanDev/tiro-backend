<?php

use App\Enums\Status;
use App\Models\Brand;
use App\Models\InventoryItem;
use App\Models\ServiceZone;
use App\Models\StockLocation;
use App\Models\TyreModel;
use App\Models\TyreVariant;

/**
 * `GET /api/v1/tyres/{slug}/availability` — the always-live price/stock
 * endpoint, deliberately separate from the PDP. See
 * docs/architecture/02-api-contract.md.
 */
function makeZoneBackedByStockLocation(): array
{
    $zone = ServiceZone::factory()->radius()->create();
    $stockLocation = StockLocation::factory()->create();
    $zone->stockLocations()->attach($stockLocation->id);

    return [$zone, $stockLocation];
}

it('requires a zone — omitting it is a 422', function () {
    $variant = TyreVariant::factory()->create();

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}/availability");

    $response->assertStatus(422)->assertJsonValidationErrors('zone');
});

it('returns 404 for an unknown slug', function () {
    [$zone] = makeZoneBackedByStockLocation();

    $response = $this->getJson("/api/v1/tyres/does-not-exist/availability?zone={$zone->id}");

    $response->assertNotFound();
});

it('returns 404 for an unknown zone id', function () {
    $variant = TyreVariant::factory()->create();

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}/availability?zone=999999");

    $response->assertNotFound();
});

it('returns 404 for a variant that is not active', function () {
    [$zone] = makeZoneBackedByStockLocation();
    $variant = TyreVariant::factory()->create(['status' => Status::Inactive]);

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}/availability?zone={$zone->id}");

    $response->assertNotFound();
});

it('returns 404 for a variant whose parent tyre model is not active', function () {
    [$zone] = makeZoneBackedByStockLocation();
    $draftModel = TyreModel::factory()->for(Brand::factory())->create(['status' => Status::Draft]);
    $variant = TyreVariant::factory()->for($draftModel, 'tyreModel')->create(['status' => Status::Active]);

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}/availability?zone={$zone->id}");

    $response->assertNotFound();
});

it('returns unit_price/currency/service_fee alongside a computed stock_status', function () {
    [$zone, $stockLocation] = makeZoneBackedByStockLocation();
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);
    InventoryItem::factory()->for($variant, 'tyreVariant')->for($stockLocation)->create([
        'qty_on_hand' => 10, 'qty_reserved' => 0,
    ]);

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}/availability?zone={$zone->id}");

    $response->assertOk()->assertExactJson([
        'data' => [
            'unit_price' => 18900,
            'promotional_price' => null,
            'currency' => 'AUD',
            'stock_status' => 'in_stock',
            'service_fee' => 0,
        ],
    ]);
});

it('reports limited stock just above the low-stock threshold', function () {
    [$zone, $stockLocation] = makeZoneBackedByStockLocation();
    $variant = TyreVariant::factory()->create();
    InventoryItem::factory()->for($variant, 'tyreVariant')->for($stockLocation)->create([
        'qty_on_hand' => 3, 'qty_reserved' => 0,
    ]);

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}/availability?zone={$zone->id}");

    $response->assertOk()->assertJsonPath('data.stock_status', 'limited');
});

it('reports out_of_stock when reservations consume all on-hand stock', function () {
    [$zone, $stockLocation] = makeZoneBackedByStockLocation();
    $variant = TyreVariant::factory()->create();
    InventoryItem::factory()->for($variant, 'tyreVariant')->for($stockLocation)->create([
        'qty_on_hand' => 2, 'qty_reserved' => 2,
    ]);

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}/availability?zone={$zone->id}");

    $response->assertOk()->assertJsonPath('data.stock_status', 'out_of_stock');
});

it('reports unavailable_in_zone when no stock location linked to the zone carries the variant', function () {
    [$zone, $stockLocation] = makeZoneBackedByStockLocation();
    $variant = TyreVariant::factory()->create();

    // Stocked somewhere real, just not at a location backing this zone.
    $otherStockLocation = StockLocation::factory()->create();
    InventoryItem::factory()->for($variant, 'tyreVariant')->for($otherStockLocation)->create([
        'qty_on_hand' => 20, 'qty_reserved' => 0,
    ]);

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}/availability?zone={$zone->id}");

    $response->assertOk()->assertJsonPath('data.stock_status', 'unavailable_in_zone');
    expect($stockLocation)->not->toBeNull();
});

it('reports unavailable_in_zone when the zone has no stock locations linked at all', function () {
    $zone = ServiceZone::factory()->radius()->create();
    $variant = TyreVariant::factory()->create();

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}/availability?zone={$zone->id}");

    $response->assertOk()->assertJsonPath('data.stock_status', 'unavailable_in_zone');
});
