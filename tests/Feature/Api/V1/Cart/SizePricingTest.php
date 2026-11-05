<?php

use App\Enums\Status;
use App\Models\Brand;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\ServiceZone;
use App\Models\SizePriceBand;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Services\Commerce\SizePricingService;
use Illuminate\Testing\TestResponse;

/**
 * Brand-agnostic, size-only pricing through the real pricing engine
 * (`POST /api/v1/cart/calculate`): a rim band's tier total replaces
 * `base_price x quantity`. Prices are cents, GST-inclusive, fitting included.
 */
beforeEach(function () {
    // The client's launch prices.
    SizePriceBand::factory()->rims(16, 17)->withTiers([16000, 26000, 36000, 36000])->create();
    SizePriceBand::factory()->rims(18, 20)->withTiers([17000, 27000, 37000, 37000])->create();
});

/** A variant of a brand-new brand and model, so tests can mix brands freely. */
function sizedVariant(int $rim, int $basePrice = 99900): TyreVariant
{
    $model = TyreModel::factory()->for(Brand::factory())->create();

    return TyreVariant::factory()->create(['tyre_model_id' => $model->id, 'rim_diameter' => $rim, 'base_price' => $basePrice]);
}

/**
 * @param  list<array{0: TyreVariant, 1: int}>  $lines
 */
function priceCart(array $lines): TestResponse
{
    return test()->postJson('/api/v1/cart/calculate', [
        'zone_id' => ServiceZone::factory()->create()->id,
        'items' => array_map(fn (array $line): array => ['tyre_variant_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
    ]);
}

it('prices 1, 2, 3 and 4 tyres of a 16-17 inch size from the band, and 4 costs the same as 3', function (int $quantity, int $total) {
    $response = priceCart([[sizedVariant(17), $quantity]])->assertOk();

    expect($response->json('data.subtotal'))->toBe($total)
        ->and($response->json('data.grand_total'))->toBe($total)
        ->and($response->json('data.lines.0.line_total'))->toBe($total);
})->with([[1, 16000], [2, 26000], [3, 36000], [4, 36000]]);

it('prices the 18-20 inch band separately', function (int $rim, int $quantity, int $total) {
    priceCart([[sizedVariant($rim), $quantity]])->assertOk()->assertJsonPath('data.grand_total', $total);
})->with([[18, 1, 17000], [19, 2, 27000], [20, 4, 37000]]);

it('ignores the variant base price and the brand', function () {
    $cheap = sizedVariant(16, 5000);
    $premium = sizedVariant(16, 80000);

    expect(priceCart([[$cheap, 2]])->json('data.grand_total'))->toBe(26000)
        ->and(priceCart([[$premium, 2]])->json('data.grand_total'))->toBe(26000);
});

it('pools tyres of different brands and sizes in the same band into one total', function () {
    $response = priceCart([[sizedVariant(16), 2], [sizedVariant(17), 2]])->assertOk();

    // 4 tyres in the 16-17 band = $360, shared across both lines to the cent.
    expect($response->json('data.grand_total'))->toBe(36000)
        ->and(collect($response->json('data.lines'))->sum('line_total'))->toBe(36000)
        ->and($response->json('data.lines.0.line_total'))->toBe(18000);
});

it('shares an uneven band total across lines without losing a cent', function () {
    $response = priceCart([[sizedVariant(16), 1], [sizedVariant(17), 2]])->assertOk();

    expect($response->json('data.grand_total'))->toBe(36000)
        ->and(collect($response->json('data.lines'))->sum('line_total'))->toBe(36000);
});

it('prices tyres in different bands separately and adds them up', function () {
    $response = priceCart([[sizedVariant(17), 2], [sizedVariant(19), 2]])->assertOk();

    expect($response->json('data.grand_total'))->toBe(26000 + 27000);
});

it('keeps base-price pricing for a size no band covers', function () {
    $response = priceCart([[sizedVariant(22, 30000), 2]])->assertOk();

    expect($response->json('data.grand_total'))->toBe(60000);
});

it('ignores an inactive band', function () {
    SizePriceBand::query()->where('rim_min', 16)->update(['status' => Status::Inactive]);
    SizePricingServiceFlush();

    expect(priceCart([[sizedVariant(17, 20000), 2]])->json('data.grand_total'))->toBe(40000);
});

function SizePricingServiceFlush(): void
{
    SizePricingService::flush();
}

it('rejects more than 4 tyres in one order, however they are split across lines', function () {
    $variant = sizedVariant(17);

    priceCart([[$variant, 5]])->assertUnprocessable();
    priceCart([[$variant, 3], [sizedVariant(19), 2]])->assertUnprocessable()->assertJsonValidationErrors('items');
});

it('applies a percentage promotion to the band price', function () {
    $variant = sizedVariant(17);
    $promotion = Promotion::factory()->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = priceCart([[$variant, 2]])->assertOk();

    expect($response->json('data.subtotal'))->toBe(26000)
        ->and($response->json('data.discount_total'))->toBe(2600)
        ->and($response->json('data.grand_total'))->toBe(23400);
});

it('never discounts a banded tyre a second time with a 4-for-3 promotion', function () {
    $variant = sizedVariant(17);
    $promotion = Promotion::factory()->fourForThree()->create();
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = priceCart([[$variant, 4]])->assertOk();

    expect($response->json('data.discount_total'))->toBe(0)
        ->and($response->json('data.grand_total'))->toBe(36000);
});

it('still gives a 4-for-3 promotion to a size no band prices', function () {
    $variant = sizedVariant(22, 20000);
    $promotion = Promotion::factory()->fourForThree()->create();
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = priceCart([[$variant, 4]])->assertOk();

    expect($response->json('data.discount_total'))->toBe(20000);
});

it('builds a 1-4 tyre price ladder from the band', function () {
    $variant = sizedVariant(17);

    $ladder = $this->getJson('/api/v1/tyres/price-ladders?ids='.$variant->id)->assertOk()->json('data.'.$variant->id.'.ladder');

    expect(collect($ladder)->pluck('quantity')->all())->toBe([1, 2, 3, 4])
        ->and(collect($ladder)->pluck('total')->all())->toBe([16000, 26000, 36000, 36000]);
});

it('does not flag a banded size as 4 for 3', function () {
    $variant = sizedVariant(17);
    $promotion = Promotion::factory()->fourForThree()->create();
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $this->getJson('/api/v1/tyres/price-ladders?ids='.$variant->id)->assertOk()->assertJsonPath('data.'.$variant->id.'.four_for_three', false);
});

it('sets a new variant\'s base price to its band\'s 1-tyre price', function () {
    expect(sizedVariant(17, 99900)->fresh()->base_price)->toBe(16000)
        ->and(sizedVariant(19, 1)->fresh()->base_price)->toBe(17000)
        ->and(sizedVariant(22, 31000)->fresh()->base_price)->toBe(31000);
});
