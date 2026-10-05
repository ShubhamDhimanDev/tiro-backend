<?php

use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\Customer;
use App\Models\PriceGuaranteeClaim;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\ServiceZone;
use App\Models\TyreVariant;

/**
 * `POST /api/v1/cart/calculate` — see
 * docs/architecture/02-api-contract.md's "Cart, Checkout & Payment
 * endpoints" section.
 */
it('mode 1: prices a raw zone_id + items cart, no auth required', function () {
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);

    $response = $this->postJson('/api/v1/cart/calculate', [
        'zone_id' => $zone->id,
        'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 4]],
    ]);

    $response->assertOk();
    expect($response->json('data.subtotal'))->toBe(75600);
    expect($response->json('data.tax_total'))->toBe(6873);
    expect($response->json('data.grand_total'))->toBe(75600);
    expect($response->json('data.lines.0.tyre_variant_id'))->toBe($variant->id);
});

/**
 * Phase 5 additive fields — see docs/architecture/02-api-contract.md's
 * "Promotions & Price-Guarantee endpoints" section: `applied_promotion`
 * (per-line) and `applied_promotions` (cart-level) are always present, not
 * omitted, even when nothing applies (asserted separately below).
 */
it('mode 1: reflects an applied percentage promotion in applied_promotion/applied_promotions and discount_total', function () {
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->create(['name' => 'Spring Sale', 'value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = $this->postJson('/api/v1/cart/calculate', [
        'zone_id' => $zone->id,
        'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 2]],
    ]);

    $response->assertOk();
    expect($response->json('data.discount_total'))->toBe(4000);
    expect($response->json('data.grand_total'))->toBe(36000);
    expect($response->json('data.lines.0.applied_promotion.id'))->toBe($promotion->id);
    expect($response->json('data.lines.0.applied_promotion.name'))->toBe('Spring Sale');
    expect($response->json('data.applied_promotions'))->toHaveCount(1);
    expect($response->json('data.applied_promotions.0.discount_amount'))->toBe(4000);
});

it('mode 1: applied_promotion is null and applied_promotions is an empty array (present, not omitted) when nothing applies', function () {
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);

    $response = $this->postJson('/api/v1/cart/calculate', [
        'zone_id' => $zone->id,
        'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1]],
    ]);

    $response->assertOk();
    expect($response->json('data.discount_total'))->toBe(0);
    expect($response->json('data.lines.0.applied_promotion'))->toBeNull();
    expect($response->json('data'))->toHaveKey('applied_promotions');
    expect($response->json('data.applied_promotions'))->toBe([]);
});

it('mode 1: applies an approved, unexpired price-guarantee claim as an additive discount for the authenticated customer', function () {
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $customer = Customer::factory()->activated()->create();

    PriceGuaranteeClaim::factory()->approved(3000)->create([
        'customer_id' => $customer->id,
        'tyre_variant_id' => $variant->id,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->postJson('/api/v1/cart/calculate', [
            'zone_id' => $zone->id,
            'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1]],
        ]);

    $response->assertOk();
    expect($response->json('data.discount_total'))->toBe(3000);
    expect($response->json('data.grand_total'))->toBe(17000);
    // Additive, not part of the Promotion pool — no applied_promotion entry
    // for a claim-only discount.
    expect($response->json('data.lines.0.applied_promotion'))->toBeNull();
});

/**
 * Security-agent review (2026-09-22, Phase 5 sign-off): the specific
 * negative-total edge case flagged in the Phase 5 task breakdown — a
 * price-guarantee claim is additive and deliberately NOT part of the
 * `Promotion` mutual-exclusivity pool (see
 * docs/architecture/05-promotions-pricing.md's "Price-guarantee claim
 * workflow" section), so nothing in the `Promotion` engine's own
 * same-pool clamp (`PromotionEvaluationService::buildResult()`) protects
 * against a claim stacking on top of an already-maximal promotion
 * discount on the same line. The actual guarantee lives one layer up, in
 * `PricingService::priceItems()` (`$remaining = max(0, $lineValue -
 * $promoDiscount); $claimDiscount = min($claim->approved_discount_amount,
 * $remaining);`) — this test proves that combination holds end-to-end
 * through the real endpoint, not just by reading the source.
 */
it('mode 1: a price-guarantee claim stacking on top of an already-maximal promotion discount never drives the line or grand_total negative', function () {
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $customer = Customer::factory()->activated()->create();

    // 100% off promotion — already discounts the entire line to zero on
    // its own, with no room left for anything else.
    $promotion = Promotion::factory()->create(['value' => 100]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    // A claim discount that, if applied unclamped on top of the
    // already-zeroed line, would drive it (and grand_total) negative.
    PriceGuaranteeClaim::factory()->approved(5000)->create([
        'customer_id' => $customer->id,
        'tyre_variant_id' => $variant->id,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->postJson('/api/v1/cart/calculate', [
            'zone_id' => $zone->id,
            'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1]],
        ]);

    $response->assertOk();
    // Combined discount is clamped to the line's own value (20000), not
    // 20000 + 5000 = 25000.
    expect($response->json('data.discount_total'))->toBe(20000);
    expect($response->json('data.lines.0.discount_amount'))->toBe(20000);
    expect($response->json('data.lines.0.line_total'))->toBe(0);
    expect($response->json('data.grand_total'))->toBe(0);
});

it('mode 1: 404s for an unresolvable zone_id', function () {
    $response = $this->postJson('/api/v1/cart/calculate', ['zone_id' => 999999]);

    $response->assertStatus(404);
});

it('mode 1: 422s for a malformed item', function () {
    $zone = ServiceZone::factory()->create();

    $response = $this->postJson('/api/v1/cart/calculate', [
        'zone_id' => $zone->id,
        'items' => [['tyre_variant_id' => 999999, 'quantity' => 0]],
    ]);

    $response->assertStatus(422);
});

it('rejects sending both zone_id and booking_id', function () {
    $zone = ServiceZone::factory()->create();
    $booking = Booking::factory()->create();

    $response = $this->postJson('/api/v1/cart/calculate', [
        'zone_id' => $zone->id,
        'booking_id' => $booking->id,
    ]);

    $response->assertStatus(422);
});

it('mode 2: prices an already-created booking for its guest owner via manage token', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);
    $booking = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('guest-token-123')]);
    BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 4]);

    $response = $this->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/cart/calculate', ['booking_id' => $booking->id]);

    $response->assertOk();
    expect($response->json('data.subtotal'))->toBe(75600);
});

it('mode 2: 403s without a valid owner/manage token', function () {
    $booking = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('guest-token-123')]);

    $response = $this->postJson('/api/v1/cart/calculate', ['booking_id' => $booking->id]);

    $response->assertStatus(403);
});

it('mode 2: 404s for a nonexistent booking_id', function () {
    $response = $this->withHeader('X-Booking-Manage-Token', 'whatever')
        ->postJson('/api/v1/cart/calculate', ['booking_id' => 999999]);

    $response->assertStatus(404);
});

it('mode 2: authenticated owner does not need a manage token', function () {
    $customer = Customer::factory()->activated()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 10000]);
    $booking = Booking::factory()->create(['customer_id' => $customer->id]);
    BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 1]);

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->postJson('/api/v1/cart/calculate', ['booking_id' => $booking->id]);

    $response->assertOk();
    expect($response->json('data.subtotal'))->toBe(10000);
});
