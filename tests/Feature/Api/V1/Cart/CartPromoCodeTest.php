<?php

use App\Enums\Status;
use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\ServiceZone;
use App\Models\TyreVariant;

/**
 * `POST /api/v1/cart/calculate` with `promo_code` — typed codes on the
 * existing promotions engine. A promotion with a `code` is never
 * auto-applied; an unusable code is a structured `promo_error`, not a 4xx.
 */
function promoCartFixture(int $price = 20000, int $quantity = 2): array
{
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => $price]);

    return [$zone, $variant, ['zone_id' => $zone->id, 'items' => [['tyre_variant_id' => $variant->id, 'quantity' => $quantity]]]];
}

function codedPromotion(TyreVariant $variant, string $code, array $attributes = []): Promotion
{
    $promotion = Promotion::factory()->withCode($code)->create(array_merge(['name' => 'Code promo', 'value' => 10], $attributes));
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    return $promotion;
}

it('applies a valid code and returns a labelled promotion line', function () {
    [, $variant, $payload] = promoCartFixture();
    $promotion = codedPromotion($variant, 'SAVE10', ['name' => 'Save 10']);

    $response = $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'SAVE10']);

    $response->assertOk();
    expect($response->json('data.promo_error'))->toBeNull()
        ->and($response->json('data.discount_total'))->toBe(4000)
        ->and($response->json('data.grand_total'))->toBe(36000)
        ->and($response->json('data.applied_promotions'))->toHaveCount(1)
        ->and($response->json('data.applied_promotions.0'))->toMatchArray([
            'id' => $promotion->id, 'label' => 'Save 10', 'amount' => 4000, 'discount_amount' => 4000, 'source' => 'code', 'code' => 'SAVE10',
        ])
        ->and($response->json('data.discount_lines'))->toBe([['type' => 'promotion', 'label' => 'Save 10', 'amount' => 4000]])
        ->and($response->json('data.lines.0.applied_promotion.id'))->toBe($promotion->id);
});

it('uses the promotion title as the label when one is set', function () {
    [, $variant, $payload] = promoCartFixture();
    codedPromotion($variant, 'SAVE10', ['name' => 'Internal name', 'title' => 'Welcome offer']);

    $line = $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'SAVE10'])->json('data.applied_promotions.0');

    expect($line['label'])->toBe('Welcome offer');
});

it('never auto-applies a coded promotion when no code is supplied', function () {
    [, $variant, $payload] = promoCartFixture();
    codedPromotion($variant, 'SAVE10');

    $response = $this->postJson('/api/v1/cart/calculate', $payload);

    expect($response->json('data.discount_total'))->toBe(0)
        ->and($response->json('data.applied_promotions'))->toBe([])
        ->and($response->json('data.promo_error'))->toBeNull();
});

it('matches codes case-insensitively and ignores surrounding whitespace', function () {
    [, $variant, $payload] = promoCartFixture();
    codedPromotion($variant, 'save10');

    $response = $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => '  Save10 ']);

    expect($response->json('data.discount_total'))->toBe(4000);
});

it('treats a blank code as no code', function () {
    [, $variant, $payload] = promoCartFixture();
    codedPromotion($variant, 'SAVE10');

    $response = $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => '   ']);

    $response->assertOk();
    expect($response->json('data.promo_error'))->toBeNull()->and($response->json('data.discount_total'))->toBe(0);
});

it('returns promo_code_invalid for an unknown code and prices the cart normally', function () {
    [, , $payload] = promoCartFixture();

    $response = $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'NOPE']);

    $response->assertOk();
    expect($response->json('data.promo_error.code'))->toBe('promo_code_invalid')
        ->and($response->json('data.promo_error.message'))->toBeString()->not->toBeEmpty()
        ->and($response->json('data.discount_total'))->toBe(0)
        ->and($response->json('data.grand_total'))->toBe(40000);
});

it('returns promo_code_invalid for a draft or inactive coded promotion', function () {
    [, $variant, $payload] = promoCartFixture();
    codedPromotion($variant, 'DRAFTED', ['status' => Status::Draft]);

    expect($this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'DRAFTED'])->json('data.promo_error.code'))->toBe('promo_code_invalid');
});

it('returns promo_code_expired for a code past its end date', function () {
    [, $variant, $payload] = promoCartFixture();
    codedPromotion($variant, 'OLD', ['starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->subDay()->toDateString()]);

    $response = $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'OLD']);

    expect($response->json('data.promo_error.code'))->toBe('promo_code_expired')
        ->and($response->json('data.discount_total'))->toBe(0);
});

it('returns promo_code_not_started for a code before its start date', function () {
    [, $variant, $payload] = promoCartFixture();
    codedPromotion($variant, 'SOON', ['starts_at' => now()->addDay()->toDateString(), 'ends_at' => now()->addMonth()->toDateString()]);

    expect($this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'SOON'])->json('data.promo_error.code'))->toBe('promo_code_not_started');
});

it('returns promo_code_exhausted when the usage limit is reached', function () {
    [, $variant, $payload] = promoCartFixture();
    codedPromotion($variant, 'GONE', ['usage_limit' => 3, 'usage_count' => 3]);

    expect($this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'GONE'])->json('data.promo_error.code'))->toBe('promo_code_exhausted');
});

it('returns promo_code_ineligible when the cart or zone does not match the code', function () {
    [$zone, $variant, $payload] = promoCartFixture();
    $other = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->withCode('OTHER')->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($other)->create(['promotion_id' => $promotion->id]);

    $response = $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'OTHER']);

    expect($response->json('data.promo_error.code'))->toBe('promo_code_ineligible')
        ->and($response->json('data.discount_total'))->toBe(0);

    $zoneScoped = Promotion::factory()->withCode('ZONED')->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->forZone(ServiceZone::factory()->create()->id)->create(['promotion_id' => $zoneScoped->id]);

    expect($this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'ZONED'])->json('data.promo_error.code'))->toBe('promo_code_ineligible');
});

it('returns promo_code_ineligible for an empty cart', function () {
    $zone = ServiceZone::factory()->create();

    $response = $this->postJson('/api/v1/cart/calculate', ['zone_id' => $zone->id, 'items' => [], 'promo_code' => 'ANY']);

    $response->assertOk();
    expect($response->json('data.promo_error.code'))->toBe('promo_code_ineligible')->and($response->json('data.grand_total'))->toBe(0);
});

it('returns promo_code_not_combinable when a better auto promotion already claimed the units', function () {
    [, $variant, $payload] = promoCartFixture();
    $auto = Promotion::factory()->create(['name' => 'Big auto sale', 'value' => 50]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $auto->id]);
    codedPromotion($variant, 'SMALL', ['value' => 5]);

    $response = $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'SMALL']);

    expect($response->json('data.promo_error.code'))->toBe('promo_code_not_combinable')
        ->and($response->json('data.applied_promotions'))->toHaveCount(1)
        ->and($response->json('data.applied_promotions.0.name'))->toBe('Big auto sale')
        ->and($response->json('data.applied_promotions.0.source'))->toBe('auto')
        ->and($response->json('data.applied_promotions.0.code'))->toBeNull();
});

it('stacks a stackable coded promotion with an auto promotion', function () {
    [, $variant, $payload] = promoCartFixture();
    $auto = Promotion::factory()->stackable()->create(['name' => 'Auto', 'value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $auto->id]);
    codedPromotion($variant, 'EXTRA', ['name' => 'Extra', 'value' => 10, 'stackable' => true]);

    $response = $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => 'EXTRA']);

    expect($response->json('data.promo_error'))->toBeNull()
        ->and($response->json('data.applied_promotions'))->toHaveCount(2)
        ->and($response->json('data.discount_total'))->toBe(8000);
});

it('leaves auto-applied promotions unchanged, now with label/amount/source fields', function () {
    [, $variant, $payload] = promoCartFixture();
    $auto = Promotion::factory()->create(['name' => 'Spring Sale', 'value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $auto->id]);

    $response = $this->postJson('/api/v1/cart/calculate', $payload);

    expect($response->json('data.discount_total'))->toBe(4000)
        ->and($response->json('data.applied_promotions.0'))->toMatchArray([
            'id' => $auto->id, 'name' => 'Spring Sale', 'type' => 'percentage', 'discount_amount' => 4000,
            'label' => 'Spring Sale', 'amount' => 4000, 'source' => 'auto', 'code' => null,
        ]);
});

it('always includes promo_error (null) and discount_lines in the response', function () {
    [, , $payload] = promoCartFixture();

    $data = $this->postJson('/api/v1/cart/calculate', $payload)->json('data');

    expect($data)->toHaveKeys(['promo_error', 'discount_lines', 'flexible_discount'])
        ->and($data['promo_error'])->toBeNull()
        ->and($data['discount_lines'])->toBe([])
        ->and($data['flexible_discount'])->toBeNull();
});

it('validates promo_code as a string of at most 40 characters', function () {
    [, , $payload] = promoCartFixture();

    $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => str_repeat('A', 41)])->assertUnprocessable()->assertJsonValidationErrors(['promo_code']);
    $this->postJson('/api/v1/cart/calculate', $payload + ['promo_code' => ['x']])->assertUnprocessable()->assertJsonValidationErrors(['promo_code']);
});

it('rejects promo_code together with booking_id: mode 2 uses the booking stored code', function () {
    $booking = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('tok')]);

    $this->withHeader('X-Booking-Manage-Token', 'tok')
        ->postJson('/api/v1/cart/calculate', ['booking_id' => $booking->id, 'promo_code' => 'SAVE10'])
        ->assertUnprocessable()->assertJsonValidationErrors(['promo_code']);
});

it('mode 2 re-applies the code stored on the booking', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    codedPromotion($variant, 'SAVE10');
    $booking = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('tok'), 'promo_code' => 'SAVE10']);
    BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 2]);

    $response = $this->withHeader('X-Booking-Manage-Token', 'tok')->postJson('/api/v1/cart/calculate', ['booking_id' => $booking->id]);

    expect($response->json('data.discount_total'))->toBe(4000)
        ->and($response->json('data.applied_promotions.0.code'))->toBe('SAVE10')
        ->and($response->json('data.promo_error'))->toBeNull();
});

it('mode 2 reports promo_error when the stored code has since expired', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    codedPromotion($variant, 'SAVE10', ['starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->subDay()->toDateString()]);
    $booking = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('tok'), 'promo_code' => 'SAVE10']);
    BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 2]);

    $response = $this->withHeader('X-Booking-Manage-Token', 'tok')->postJson('/api/v1/cart/calculate', ['booking_id' => $booking->id]);

    expect($response->json('data.promo_error.code'))->toBe('promo_code_expired')->and($response->json('data.discount_total'))->toBe(0);
});
