<?php

use App\Enums\BrandTier;
use App\Enums\Status;
use App\Models\Brand;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\ServiceZone;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Models\Vehicle;
use App\Models\VehicleFitment;

/**
 * `GET /api/v1/tyres/facets` and `GET /api/v1/tyres/price-ladders` (see
 * docs/redesign/api-contract-phase7.md sections 2 and 3).
 */
function facetVariant(Brand $brand, array $variant = [], array $model = []): TyreVariant
{
    $tyreModel = TyreModel::factory()->create(array_merge(['brand_id' => $brand->id], $model));

    return TyreVariant::factory()->create(array_merge([
        'tyre_model_id' => $tyreModel->id, 'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '91', 'speed_rating' => 'V', 'base_price' => 20000,
    ], $variant));
}

it('returns option lists with counts for a size', function () {
    $premium = Brand::factory()->tier(BrandTier::Premium)->create(['name' => 'Alpha']);
    $budget = Brand::factory()->tier(BrandTier::Budget)->create(['name' => 'Beta']);
    facetVariant($premium, ['base_price' => 30000, 'load_index' => '95', 'speed_rating' => 'W'], ['run_flat' => true]);
    facetVariant($budget, ['base_price' => 12000, 'load_index' => '88', 'speed_rating' => 'H']);
    facetVariant($budget, ['width' => 225]);

    $vehicle = Vehicle::factory()->create(['make' => 'Mazda']);
    VehicleFitment::factory()->create(['vehicle_id' => $vehicle->id, 'width' => 205, 'profile' => 55, 'rim_diameter' => 16]);

    $data = $this->getJson('/api/v1/tyres/facets?width=205&profile=55&rim_diameter=16')->assertOk()->json('data');

    expect($data['total'])->toBe(2)
        ->and(collect($data['brands'])->pluck('name')->all())->toBe(['Alpha', 'Beta'])
        ->and($data['patterns'])->toHaveCount(2)
        ->and($data['tiers'])->toBe([['value' => 'premium', 'count' => 1], ['value' => 'budget', 'count' => 1]])
        ->and($data['run_flat'])->toBe(['yes' => 1, 'no' => 1])
        ->and($data['price'])->toBe(['min' => 12000, 'max' => 30000])
        ->and($data['load_index'])->toBe(['min' => 88, 'max' => 95])
        ->and($data['speed_ratings'])->toBe(['H', 'W'])
        ->and($data['car_makes'])->toBe([['make' => 'Mazda', 'count' => 1]]);
});

it('returns an empty facet set when nothing matches', function () {
    $data = $this->getJson('/api/v1/tyres/facets?width=205&profile=55&rim_diameter=16')->assertOk()->json('data');

    expect($data['total'])->toBe(0)->and($data['price'])->toBeNull()->and($data['brands'])->toBe([]);
});

it('excludes inactive brands from facets', function () {
    $brand = Brand::factory()->create(['status' => Status::Inactive]);
    facetVariant($brand);

    $this->getJson('/api/v1/tyres/facets')->assertOk()->assertJsonPath('data.total', 0);
});

it('builds a quantity ladder from the real pricing engine including 4 for 3', function () {
    $brand = Brand::factory()->create();
    $variant = facetVariant($brand, ['base_price' => 20000]);
    $promotion = Promotion::factory()->fourForThree()->create();
    PromotionEligibility::factory()->forBrand($brand->id)->create(['promotion_id' => $promotion->id]);

    $ladder = $this->getJson('/api/v1/tyres/price-ladders?ids='.$variant->id)->assertOk()->json('data.'.$variant->id);

    expect($ladder['list_price'])->toBe(20000)
        ->and($ladder['four_for_three'])->toBeTrue()
        ->and(collect($ladder['ladder'])->pluck('unit_price')->all())->toBe([20000, 20000, 20000, 15000, 16000])
        ->and($ladder['ladder'][3])->toMatchArray(['quantity' => 4, 'total' => 60000, 'discount_total' => 20000]);
});

it('gives a flat ladder when no promotion applies and skips unknown or inactive ids', function () {
    $variant = facetVariant(Brand::factory()->create(), ['base_price' => 18000]);
    $inactive = facetVariant(Brand::factory()->create(), ['status' => Status::Inactive]);

    $data = $this->getJson('/api/v1/tyres/price-ladders?ids='.$variant->id.','.$inactive->id.',999999')->assertOk()->json('data');

    expect(array_keys($data))->toBe([$variant->id])
        ->and(collect($data[$variant->id]['ladder'])->pluck('unit_price')->unique()->all())->toBe([18000])
        ->and($data[$variant->id]['four_for_three'])->toBeFalse();
});

it('validates price-ladder ids and zone', function () {
    $this->getJson('/api/v1/tyres/price-ladders')->assertUnprocessable()->assertJsonValidationErrors('ids');
    $this->getJson('/api/v1/tyres/price-ladders?ids=1,abc')->assertUnprocessable()->assertJsonValidationErrors('ids');
    $this->getJson('/api/v1/tyres/price-ladders?ids='.implode(',', range(1, 25)))->assertUnprocessable();
    $this->getJson('/api/v1/tyres/price-ladders?ids=1&zone=999999')->assertNotFound();
});

it('accepts a zone for the ladder', function () {
    $zone = ServiceZone::factory()->create();
    $variant = facetVariant(Brand::factory()->create());

    $this->getJson('/api/v1/tyres/price-ladders?ids='.$variant->id.'&zone='.$zone->id)->assertOk();
});
