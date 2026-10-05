<?php

use App\Enums\PromotionEligibilityScope;
use App\Enums\PromotionRedemptionStatus;
use App\Enums\TyreCategory;
use App\Models\Booking;
use App\Models\Brand;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\PromotionRedemption;
use App\Models\ServiceZone;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Services\Commerce\CartItemInput;
use App\Services\Promotions\PromotionEvaluationService;

/**
 * Core algorithm coverage for {@see PromotionEvaluationService} — see
 * docs/architecture/05-promotions-pricing.md's "Promotion evaluation
 * algorithm" and "4-for-3 mechanics" sections. Implemented exactly as
 * specified there; these tests pin that exact behavior down.
 */
beforeEach(function () {
    $this->service = app(PromotionEvaluationService::class);
});

it('applies a percentage discount per matched unit, scoped to a single tyre_variant', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);

    $promotion = Promotion::factory()->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $items = collect([new CartItemInput($variant->id, 2)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->discountTotal)->toBe(4000); // 10% of 20000 * 2
    expect($result->discountForLine(0))->toBe(4000);
    expect($result->primaryPromotionForLine(0)['id'])->toBe($promotion->id);
});

it('clamps a fixed discount to the unit price so it can never go negative', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 5000]);

    $promotion = Promotion::factory()->fixed(9999999)->create();
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $items = collect([new CartItemInput($variant->id, 1)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->discountTotal)->toBe(5000);
});

it('4-for-3: pools units across different SKUs sharing a brand, sorts descending, and discounts only the cheapest unit in each complete group of 4', function () {
    $brand = Brand::factory()->create();
    $tyreModel = TyreModel::factory()->create(['brand_id' => $brand->id]);

    // 5 units total, prices: 500, 400, 300, 200, 100 (one variant qty 4 at
    // varying... build as distinct variants each qty 1 for clean per-unit
    // pricing).
    $prices = [50000, 40000, 30000, 20000, 10000];
    $variants = collect($prices)->map(fn (int $price) => TyreVariant::factory()->create([
        'tyre_model_id' => $tyreModel->id,
        'base_price' => $price,
    ]));

    $promotion = Promotion::factory()->fourForThree()->create();
    PromotionEligibility::factory()->create([
        'promotion_id' => $promotion->id,
        'scope' => PromotionEligibilityScope::Brand,
        'scope_id' => (string) $brand->id,
    ]);

    $items = $variants->map(fn (TyreVariant $v) => new CartItemInput($v->id, 1));

    $result = $this->service->evaluate($items, serviceZoneId: null);

    // Complete group of 4 = the 4 most expensive units (500,400,300,200);
    // the cheapest of that group (200) is 100% off. The 5th (100, the
    // overall cheapest) is a leftover partial group — no discount.
    expect($result->discountTotal)->toBe(20000);
});

it('4-for-3: a trailing partial group (fewer than 4 units) gets no discount at all', function () {
    $brand = Brand::factory()->create();
    $tyreModel = TyreModel::factory()->create(['brand_id' => $brand->id]);
    $variants = collect([10000, 20000, 30000])->map(fn (int $price) => TyreVariant::factory()->create([
        'tyre_model_id' => $tyreModel->id,
        'base_price' => $price,
    ]));

    $promotion = Promotion::factory()->fourForThree()->create();
    PromotionEligibility::factory()->create([
        'promotion_id' => $promotion->id,
        'scope' => PromotionEligibilityScope::Brand,
        'scope_id' => (string) $brand->id,
    ]);

    $items = $variants->map(fn (TyreVariant $v) => new CartItemInput($v->id, 1));

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->discountTotal)->toBe(0);
});

it('mutual exclusivity: a lower-total, non-stackable candidate is skipped when it overlaps an already-claimed unit', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);

    $bigDiscount = Promotion::factory()->create(['value' => 50]); // 5000/unit
    $smallDiscount = Promotion::factory()->create(['value' => 10]); // 1000/unit, lower id would win ties but here it's simply smaller

    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $bigDiscount->id]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $smallDiscount->id]);

    $items = collect([new CartItemInput($variant->id, 1)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->discountTotal)->toBe(5000);
    expect($result->applied)->toHaveCount(1);
    expect($result->applied[0]->promotion->id)->toBe($bigDiscount->id);
});

it('stacking: a stackable candidate applies on top of an already-claimed unit, clamped so the unit never goes negative', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);

    $primary = Promotion::factory()->create(['value' => 50]); // 5000/unit
    $stacked = Promotion::factory()->stackable()->create(['value' => 30]); // 3000/unit

    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $primary->id]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $stacked->id]);

    $items = collect([new CartItemInput($variant->id, 1)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->applied)->toHaveCount(2);
    expect($result->discountTotal)->toBe(8000); // 5000 + 3000, still under 10000
});

it('excludes a promotion once usage_count reaches usage_limit', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);
    $promotion = Promotion::factory()->withUsageLimit(5)->create(['usage_count' => 5, 'value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $items = collect([new CartItemInput($variant->id, 1)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->applied)->toHaveCount(0);
});

it('stock_limit, no booking context: excludes a promotion once existing held/confirmed quantity exhausts stock_limit', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);
    $promotion = Promotion::factory()->withStockLimit(3)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $booking = Booking::factory()->create();
    PromotionRedemption::factory()->create([
        'promotion_id' => $promotion->id,
        'booking_id' => $booking->id,
        'quantity' => 3,
        'status' => PromotionRedemptionStatus::Held,
    ]);

    $items = collect([new CartItemInput($variant->id, 1)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->applied)->toHaveCount(0);
});

it('stock_limit, hold-aware booking context: only applies if THIS booking already holds a redemption for it', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);
    $promotion = Promotion::factory()->withStockLimit(10)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $bookingWithHold = Booking::factory()->create();
    PromotionRedemption::factory()->create([
        'promotion_id' => $promotion->id,
        'booking_id' => $bookingWithHold->id,
        'status' => PromotionRedemptionStatus::Held,
    ]);

    $bookingWithoutHold = Booking::factory()->create();

    $items = collect([new CartItemInput($variant->id, 1)]);

    $withHold = $this->service->evaluate($items, serviceZoneId: null, bookingId: $bookingWithHold->id);
    $withoutHold = $this->service->evaluate($items, serviceZoneId: null, bookingId: $bookingWithoutHold->id);

    expect($withHold->applied)->toHaveCount(1);
    expect($withoutHold->applied)->toHaveCount(0);
});

it('a promotion outside its date window is never a candidate', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);
    $promotion = Promotion::factory()->create([
        'value' => 10,
        'starts_at' => now()->subDays(10)->toDateString(),
        'ends_at' => now()->subDay()->toDateString(),
    ]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $items = collect([new CartItemInput($variant->id, 1)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->applied)->toHaveCount(0);
});

it('an eligibility row scoped to a different zone does not match', function () {
    $eligibleZone = ServiceZone::factory()->create();
    $otherZone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);
    $promotion = Promotion::factory()->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->forZone($eligibleZone->id)->create(['promotion_id' => $promotion->id]);

    $items = collect([new CartItemInput($variant->id, 1)]);

    $result = $this->service->evaluate($items, serviceZoneId: $otherZone->id);

    expect($result->applied)->toHaveCount(0);
});

it('a category-scoped eligibility matches by the TyreCategory string value, not a fabricated numeric id', function () {
    $tyreModel = TyreModel::factory()->create(['category' => TyreCategory::Suv]);
    $variant = TyreVariant::factory()->create(['tyre_model_id' => $tyreModel->id, 'base_price' => 10000]);

    $promotion = Promotion::factory()->create(['value' => 10]);
    PromotionEligibility::factory()->create([
        'promotion_id' => $promotion->id,
        'scope' => PromotionEligibilityScope::Category,
        'scope_id' => 'suv',
    ]);

    $items = collect([new CartItemInput($variant->id, 1)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->applied)->toHaveCount(1);
});

/**
 * Regression coverage for the quantity-aware stock_limit fix (2026-09-22):
 * the re-check is `existing consumption + this candidate's own consumed
 * quantity <= stock_limit`, not a boolean "is there any room at all" —
 * otherwise a request for more than one unit against a near-exhausted
 * stock_limit could oversell it.
 */
it('stock_limit is quantity-aware: rejects a candidate whose own consumed quantity would push existing consumption past the limit', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);
    $promotion = Promotion::factory()->withStockLimit(3)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $booking = Booking::factory()->create();
    PromotionRedemption::factory()->create([
        'promotion_id' => $promotion->id,
        'booking_id' => $booking->id,
        'quantity' => 2,
        'status' => PromotionRedemptionStatus::Held,
    ]);

    // Existing consumption (2) is still < stock_limit (3) — a boolean "any
    // room" check would incorrectly allow this — but this request wants 2
    // more units, 2 + 2 = 4 > 3, so it must be rejected.
    $items = collect([new CartItemInput($variant->id, 2)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->applied)->toHaveCount(0);
});

it('stock_limit is quantity-aware: accepts a candidate whose own consumed quantity exactly fills the remaining allocation', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);
    $promotion = Promotion::factory()->withStockLimit(3)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $booking = Booking::factory()->create();
    PromotionRedemption::factory()->create([
        'promotion_id' => $promotion->id,
        'booking_id' => $booking->id,
        'quantity' => 1,
        'status' => PromotionRedemptionStatus::Held,
    ]);

    // 1 existing + 2 requested = 3, exactly at stock_limit — must fit.
    $items = collect([new CartItemInput($variant->id, 2)]);

    $result = $this->service->evaluate($items, serviceZoneId: null);

    expect($result->applied)->toHaveCount(1);
});
