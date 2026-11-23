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
 * Fitting pricing through the real pricing engine: the tyre keeps its own
 * price and a fitting charge, set by the number of tyres, is added (folded
 * into the line, never a separate charge). The client's fitting is the same
 * for every rim size: $200 / $300 / $400 / $400 for 1 / 2 / 3 / 4 tyres.
 * Cents, GST-inclusive.
 */
beforeEach(function () {
    SizePriceBand::factory()->rims(10, 30)->withTiers([20000, 30000, 40000, 40000])->create();
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

it('adds the fitting to the tyre price for 1 to 4 tyres, and 4 costs the same fitting as 3', function (int $quantity, int $fitting) {
    $response = priceCart([[sizedVariant(17, 10000), $quantity]])->assertOk();

    $expected = 10000 * $quantity + $fitting;

    expect($response->json('data.subtotal'))->toBe($expected)
        ->and($response->json('data.grand_total'))->toBe($expected)
        ->and($response->json('data.lines.0.line_total'))->toBe($expected);
})->with([[1, 20000], [2, 30000], [3, 40000], [4, 40000]]);

it('charges the same fitting whatever the rim size', function (int $rim) {
    priceCart([[sizedVariant($rim, 10000), 2]])->assertOk()->assertJsonPath('data.grand_total', 20000 + 30000);
})->with([13, 15, 16, 17, 18, 19, 20, 22, 24]);

it('keeps the tyre price per brand: only the fitting is added', function () {
    $cheap = sizedVariant(16, 5000);
    $premium = sizedVariant(21, 30000);

    expect(priceCart([[$cheap, 2]])->json('data.grand_total'))->toBe(2 * 5000 + 30000)
        ->and(priceCart([[$premium, 2]])->json('data.grand_total'))->toBe(2 * 30000 + 30000);
});

it('shares one fitting total across lines', function () {
    $response = priceCart([[sizedVariant(16, 10000), 2], [sizedVariant(21, 20000), 2]])->assertOk();

    // 4 tyres = $400 fitting, split across both lines to the cent.
    expect($response->json('data.grand_total'))->toBe(2 * 10000 + 2 * 20000 + 40000)
        ->and($response->json('data.lines.0.line_total'))->toBe(2 * 10000 + 20000)
        ->and(collect($response->json('data.lines'))->sum('line_total'))->toBe(100000);
});

it('shares an uneven fitting total across lines without losing a cent', function () {
    $response = priceCart([[sizedVariant(16, 10000), 1], [sizedVariant(17, 10000), 2]])->assertOk();

    expect($response->json('data.grand_total'))->toBe(3 * 10000 + 40000)
        ->and(collect($response->json('data.lines'))->sum('line_total'))->toBe(70000);
});

it('still charges fitting per band when an admin sets different bands up', function () {
    SizePriceBand::query()->delete();
    SizePricingService::flush();
    SizePriceBand::factory()->rims(10, 17)->withTiers([16000, 26000, 36000, 37000])->create();
    SizePriceBand::factory()->rims(18, 30)->withTiers([17000, 27000, 37000, 38000])->create();

    $response = priceCart([[sizedVariant(17, 10000), 2], [sizedVariant(19, 10000), 2]])->assertOk();

    expect($response->json('data.grand_total'))->toBe(40000 + 26000 + 27000);
});

it('adds no fitting to a size no band covers', function () {
    priceCart([[sizedVariant(35, 30000), 2]])->assertOk()->assertJsonPath('data.grand_total', 60000);
});

it('adds no fitting when the band is inactive', function () {
    SizePriceBand::query()->update(['status' => Status::Inactive]);
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

    expect($response->json('data.subtotal'))->toBe(20000 + 30000)
        ->and($response->json('data.discount_total'))->toBe(2000)
        ->and($response->json('data.grand_total'))->toBe(48000);
});

it('still gives a 4-for-3 promotion its free tyre', function () {
    $variant = sizedVariant(17, 10000);
    $promotion = Promotion::factory()->fourForThree()->create();
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = priceCart([[$variant, 4]])->assertOk();

    expect($response->json('data.discount_total'))->toBe(10000)
        ->and($response->json('data.grand_total'))->toBe(40000 + 40000 - 10000);
});

it('builds a 1-4 tyre price ladder that includes the fitting', function () {
    $variant = sizedVariant(17, 10000);

    $ladder = $this->getJson('/api/v1/tyres/price-ladders?ids='.$variant->id)->assertOk()->json('data.'.$variant->id);

    expect($ladder['list_price'])->toBe(30000)
        ->and(collect($ladder['ladder'])->pluck('quantity')->all())->toBe([1, 2, 3, 4])
        ->and(collect($ladder['ladder'])->pluck('total')->all())->toBe([30000, 50000, 70000, 80000]);
});

it('shows the fitted one-tyre price as the listing price', function () {
    $variant = sizedVariant(19, 10000);
    $unbanded = sizedVariant(35, 10000);

    $data = collect($this->getJson('/api/v1/tyres?per_page=50')->assertOk()->json('data'))->keyBy('id');

    expect($data[$variant->id]['list_price'])->toBe(30000)
        ->and($data[$unbanded->id]['list_price'])->toBe(10000);
});

it('sorts and filters the listing by the fitted price', function () {
    // 16" tyre 20000 + 20000 fitting = 40000; 19" tyre 15000 + 20000 = 35000; 35" tyre 33000 + 0 = 33000.
    $a = sizedVariant(16, 20000);
    $b = sizedVariant(19, 15000);
    $c = sizedVariant(35, 33000);

    $ids = fn (string $query): array => collect($this->getJson('/api/v1/tyres?per_page=50&'.$query)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('sort=price_asc'))->toBe([$c->id, $b->id, $a->id])
        ->and($ids('sort=price_desc'))->toBe([$a->id, $b->id, $c->id])
        ->and($ids('price_min=340&price_max=380'))->toEqualCanonicalizing([$b->id]);
});
