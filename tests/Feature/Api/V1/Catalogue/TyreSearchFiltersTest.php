<?php

use App\Enums\BrandTier;
use App\Enums\TyreType;
use App\Models\Brand;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Models\Vehicle;
use App\Models\VehicleFitment;

/**
 * Phase 7 filters on `GET /api/v1/tyres` (see
 * docs/redesign/api-contract-phase7.md section 1).
 */
function filterVariant(array $variant = [], array $model = [], ?Brand $brand = null): TyreVariant
{
    $brand ??= Brand::factory()->tier(BrandTier::Mid)->create();
    $tyreModel = TyreModel::factory()->create(array_merge(['brand_id' => $brand->id], $model));

    return TyreVariant::factory()->create(array_merge([
        'tyre_model_id' => $tyreModel->id, 'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '91', 'speed_rating' => 'V', 'base_price' => 20000,
    ], $variant));
}

function filterIds(string $query): array
{
    return collect(test()->getJson('/api/v1/tyres?per_page=100&'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
}

it('filters by minimum load index numerically', function () {
    $low = filterVariant(['load_index' => '86']);
    $high = filterVariant(['load_index' => '102']);

    expect(filterIds('min_load=95'))->toBe([$high->id])
        ->and(filterIds('min_load=80'))->toBe([$low->id, $high->id]);
});

it('filters by minimum speed rating using the rating order', function () {
    $t = filterVariant(['speed_rating' => 'T']);
    $v = filterVariant(['speed_rating' => 'V']);
    $y = filterVariant(['speed_rating' => 'Y']);

    expect(filterIds('min_speed=V'))->toBe([$v->id, $y->id])
        ->and(filterIds('min_speed=T'))->toBe([$t->id, $v->id, $y->id]);
});

it('filters run-flat yes/no', function () {
    $flat = filterVariant(model: ['run_flat' => true]);
    $standard = filterVariant(model: ['run_flat' => false]);

    expect(filterIds('runflat=yes'))->toBe([$flat->id])
        ->and(filterIds('runflat=no'))->toBe([$standard->id])
        ->and(filterIds(''))->toBe([$flat->id, $standard->id]);
});

it('filters by pattern (tyre model slug) with several values', function () {
    $a = filterVariant();
    $b = filterVariant();
    filterVariant();

    expect(filterIds('pattern='.$a->tyreModel->slug.','.$b->tyreModel->slug))->toBe([$a->id, $b->id]);
});

it('filters by price range in whole dollars', function () {
    $cheap = filterVariant(['base_price' => 15000]);
    $mid = filterVariant(['base_price' => 25000]);
    $dear = filterVariant(['base_price' => 40000]);

    expect(filterIds('price_min=200&price_max=300'))->toBe([$mid->id])
        ->and(filterIds('price_max=250'))->toBe([$cheap->id, $mid->id])
        ->and(filterIds('price_min=250'))->toBe([$mid->id, $dear->id]);
});

it('accepts several brands, types, categories and tiers at once', function () {
    $premium = Brand::factory()->tier(BrandTier::Premium)->create();
    $budget = Brand::factory()->tier(BrandTier::Budget)->create();
    $one = filterVariant(brand: $premium, model: ['tyre_type' => TyreType::Highway]);
    $two = filterVariant(brand: $budget, model: ['tyre_type' => TyreType::Eco]);
    filterVariant(model: ['tyre_type' => TyreType::Performance]);

    expect(filterIds('brand='.$premium->slug.','.$budget->slug))->toBe([$one->id, $two->id])
        ->and(filterIds('tyre_type=highway,eco'))->toBe([$one->id, $two->id])
        ->and(filterIds('tier=premium,budget'))->toBe([$one->id, $two->id])
        ->and(filterIds('tier=premium'))->toBe([$one->id]);
});

it('filters by car make through vehicle fitments of the same size', function () {
    $fits = filterVariant(['width' => 225, 'profile' => 60, 'rim_diameter' => 17]);
    filterVariant(['width' => 195, 'profile' => 65, 'rim_diameter' => 15]);
    $vehicle = Vehicle::factory()->create(['make' => 'Subaru']);
    VehicleFitment::factory()->create(['vehicle_id' => $vehicle->id, 'width' => 225, 'profile' => 60, 'rim_diameter' => 17]);

    expect(filterIds('car_make=Subaru'))->toBe([$fits->id])
        ->and(filterIds('car_make=Nobody'))->toBe([]);
});

it('exposes list_price, run_flat, pattern and the four_for_three flag on items', function () {
    $brand = Brand::factory()->create();
    $promoted = filterVariant(['base_price' => 30000], ['run_flat' => true], $brand);
    $other = filterVariant(['base_price' => 18000]);

    $promotion = Promotion::factory()->fourForThree()->create();
    PromotionEligibility::factory()->forBrand($brand->id)->create(['promotion_id' => $promotion->id]);

    $items = collect($this->getJson('/api/v1/tyres?per_page=100')->assertOk()->json('data'))->keyBy('id');

    expect($items[$promoted->id])->toMatchArray([
        'list_price' => 30000, 'run_flat' => true, 'pattern' => $promoted->tyreModel->slug, 'four_for_three' => true,
    ])->and($items[$other->id]['four_for_three'])->toBeFalse()
        ->and($items[$other->id]['list_price'])->toBe(18000);
});

it('does not flag four_for_three for code-gated promotions', function () {
    $brand = Brand::factory()->create();
    $variant = filterVariant(brand: $brand);
    $promotion = Promotion::factory()->fourForThree()->withCode('SETOF4')->create();
    PromotionEligibility::factory()->forBrand($brand->id)->create(['promotion_id' => $promotion->id]);

    $this->getJson('/api/v1/tyres?per_page=100')->assertOk()->assertJsonPath('data.0.four_for_three', false);
    expect($variant->id)->toBeInt();
});

it('rejects invalid new filter values', function (string $query, string $field) {
    $this->getJson('/api/v1/tyres?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'speed letter' => ['min_speed=Z', 'min_speed'],
    'runflat word' => ['runflat=maybe', 'runflat'],
    'min load too big' => ['min_load=500', 'min_load'],
    'bad tier' => ['tier=luxury', 'tier'],
    'bad type in list' => ['tyre_type=highway,flying', 'tyre_type'],
    'negative price' => ['price_min=-5', 'price_min'],
]);
