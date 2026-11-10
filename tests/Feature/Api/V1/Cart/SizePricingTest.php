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
 * Size-based fitting pricing through the real pricing engine: the tyre keeps
 * its own price and a fitting charge, set by rim band and tyre count, is added
 * (folded into the line, never a separate charge). Cents, GST-inclusive.
 */
beforeEach(function () {
    // The client's fitting prices.
    SizePriceBand::factory()->rims(16, 17)->withTiers([16000, 26000, 36000, 37000])->create();
    SizePriceBand::factory()->rims(18, 30)->withTiers([17000, 27000, 37000, 38000])->create();
});

/** A variant of a brand-new brand and model, so tests can mix brands freely. */
function sizedVariant(int $rim, int $basePrice = 10000): TyreVariant
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

it('adds the 16-17 inch fitting to the tyre price for 1 to 4 tyres', function (int $quantity, int $fitting) {
    $response = priceCart([[sizedVariant(17, 10000), $quantity]])->assertOk();

    $expected = 10000 * $quantity + $fitting;

    expect($response->json('data.subtotal'))->toBe($expected)
        ->and($response->json('data.grand_total'))->toBe($expected)
        ->and($response->json('data.lines.0.line_total'))->toBe($expected);
})->with([[1, 16000], [2, 26000], [3, 36000], [4, 37000]]);

it('adds the 18 inch and larger fitting', function (int $rim, int $quantity, int $fitting) {
    priceCart([[sizedVariant($rim, 10000), $quantity]])->assertOk()
        ->assertJsonPath('data.grand_total', 10000 * $quantity + $fitting);
})->with([[18, 1, 17000], [19, 2, 27000], [20, 3, 37000], [20, 4, 38000], [24, 4, 38000]]);

it('keeps the tyre price per brand: only the fitting is size-based', function () {
    $cheap = sizedVariant(16, 5000);
    $premium = sizedVariant(16, 30000);

    expect(priceCart([[$cheap, 2]])->json('data.grand_total'))->toBe(2 * 5000 + 26000)
        ->and(priceCart([[$premium, 2]])->json('data.grand_total'))->toBe(2 * 30000 + 26000);
});

it('shares one fitting total across lines in the same band', function () {
    $response = priceCart([[sizedVariant(16, 10000), 2], [sizedVariant(17, 20000), 2]])->assertOk();

    // 4 tyres in the 16-17 band = $370 fitting, split across both lines to the cent.
    expect($response->json('data.grand_total'))->toBe(2 * 10000 + 2 * 20000 + 37000)
        ->and($response->json('data.lines.0.line_total'))->toBe(2 * 10000 + 18500)
        ->and(collect($response->json('data.lines'))->sum('line_total'))->toBe(97000);
});

it('shares an uneven fitting total across lines without losing a cent', function () {
    $response = priceCart([[sizedVariant(16, 10000), 1], [sizedVariant(17, 10000), 2]])->assertOk();

    expect($response->json('data.grand_total'))->toBe(3 * 10000 + 36000)
        ->and(collect($response->json('data.lines'))->sum('line_total'))->toBe(66000);
});

it('charges fitting per band and adds them up', function () {
    $response = priceCart([[sizedVariant(17, 10000), 2], [sizedVariant(19, 10000), 2]])->assertOk();

    expect($response->json('data.grand_total'))->toBe(40000 + 26000 + 27000);
});

it('adds no fitting to a size no band covers', function () {
    priceCart([[sizedVariant(35, 30000), 2]])->assertOk()->assertJsonPath('data.grand_total', 60000);
});

it('ignores an inactive band', function () {
    SizePriceBand::query()->where('rim_min', 16)->update(['status' => Status::Inactive]);
    SizePricingService::flush();

    priceCart([[sizedVariant(17, 20000), 2]])->assertOk()->assertJsonPath('data.grand_total', 40000);
});

it('rejects more than 4 tyres in one order, however they are split across lines', function () {
    $variant = sizedVariant(17);

    priceCart([[$variant, 5]])->assertUnprocessable();
    priceCart([[$variant, 3], [sizedVariant(19), 2]])->assertUnprocessable()->assertJsonValidationErrors('items');
});

it('applies a percentage promotion to the tyre price only, not the fitting', function () {
    $variant = sizedVariant(17, 10000);
    $promotion = Promotion::factory()->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = priceCart([[$variant, 2]])->assertOk();

    expect($response->json('data.subtotal'))->toBe(20000 + 26000)
        ->and($response->json('data.discount_total'))->toBe(2000)
        ->and($response->json('data.grand_total'))->toBe(44000);
});

it('still gives a 4-for-3 promotion its free tyre on a banded size', function () {
    $variant = sizedVariant(17, 10000);
    $promotion = Promotion::factory()->fourForThree()->create();
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = priceCart([[$variant, 4]])->assertOk();

    expect($response->json('data.discount_total'))->toBe(10000)
        ->and($response->json('data.grand_total'))->toBe(40000 + 37000 - 10000);
});

it('builds a 1-4 tyre price ladder that includes the fitting', function () {
    $variant = sizedVariant(17, 10000);

    $ladder = $this->getJson('/api/v1/tyres/price-ladders?ids='.$variant->id)->assertOk()->json('data.'.$variant->id);

    expect($ladder['list_price'])->toBe(26000)
        ->and(collect($ladder['ladder'])->pluck('quantity')->all())->toBe([1, 2, 3, 4])
        ->and(collect($ladder['ladder'])->pluck('total')->all())->toBe([26000, 46000, 66000, 77000]);
});

it('shows the fitted one-tyre price as the listing price', function () {
    $variant = sizedVariant(19, 10000);
    $unbanded = sizedVariant(35, 10000);

    $data = collect($this->getJson('/api/v1/tyres?per_page=50')->assertOk()->json('data'))->keyBy('id');

    expect($data[$variant->id]['list_price'])->toBe(27000)
        ->and($data[$unbanded->id]['list_price'])->toBe(10000);
});

it('sorts and filters the listing by the fitted price', function () {
    // 16" tyre 20000 + 16000 fitting = 36000; 19" tyre 15000 + 17000 = 32000; 35" tyre 33000 + 0 = 33000.
    $a = sizedVariant(16, 20000);
    $b = sizedVariant(19, 15000);
    $c = sizedVariant(35, 33000);

    $ids = fn (string $query): array => collect($this->getJson('/api/v1/tyres?per_page=50&'.$query)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('sort=price_asc'))->toBe([$b->id, $c->id, $a->id])
        ->and($ids('sort=price_desc'))->toBe([$a->id, $c->id, $b->id])
        ->and($ids('price_min=330&price_max=350'))->toEqualCanonicalizing([$c->id]);
});
