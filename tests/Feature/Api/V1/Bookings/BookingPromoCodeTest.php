<?php

use App\Models\Booking;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * `POST /api/v1/bookings` with `promo_code` — the typed code is validated at
 * hold time and stored on the booking so the checkout recompute re-applies it.
 */
beforeEach(function () {
    seedDurationRules();
    Queue::fake();
});

function promoBookingPayload($zone, TyreVariant $variant, array $overrides = []): array
{
    return storeBookingPayload($zone, array_merge([
        'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 2, 'position' => 'all']],
    ], $overrides));
}

function promoBookingVariant(string $code = 'SAVE10', array $promotion = []): TyreVariant
{
    $variant = TyreVariant::factory()->create(['tyre_model_id' => TyreModel::factory(), 'base_price' => 20000]);
    $promo = Promotion::factory()->withCode($code)->create(array_merge(['value' => 10], $promotion));
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promo->id]);

    return $variant;
}

it('stores a valid code (normalised) on the booking and echoes it', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = promoBookingVariant();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', promoBookingPayload($zone, $variant, ['promo_code' => ' save10 ']));

    $response->assertCreated();
    expect($response->json('data.promo_code'))->toBe('SAVE10')
        ->and(Booking::query()->findOrFail($response->json('data.id'))->promo_code)->toBe('SAVE10');
});

it('rejects an unusable code with 422 and a structured promo_error, creating no booking', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = promoBookingVariant();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', promoBookingPayload($zone, $variant, ['promo_code' => 'WRONG']));

    $response->assertUnprocessable();
    expect($response->json('promo_error.code'))->toBe('promo_code_invalid')
        ->and($response->json('errors.promo_code.0'))->toBe($response->json('promo_error.message'))
        ->and(Booking::query()->count())->toBe(0);
});

it('rejects an expired code at hold time', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = promoBookingVariant('OLD', ['starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->subDay()->toDateString()]);

    $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', promoBookingPayload($zone, $variant, ['promo_code' => 'OLD']))
        ->assertUnprocessable()->assertJsonPath('promo_error.code', 'promo_code_expired');
});

it('books normally without a code', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = promoBookingVariant();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/bookings', promoBookingPayload($zone, $variant));

    $response->assertCreated();
    expect($response->json('data.promo_code'))->toBeNull();
});

it('the checkout recompute (cart/calculate mode 2) applies the stored code', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = promoBookingVariant();

    $created = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', promoBookingPayload($zone, $variant, ['promo_code' => 'SAVE10']));

    $priced = $this->withHeader('X-Booking-Manage-Token', $created->json('data.manage_token'))
        ->postJson('/api/v1/cart/calculate', ['booking_id' => $created->json('data.id')]);

    expect($priced->json('data.discount_total'))->toBe(4000)->and($priced->json('data.applied_promotions.0.source'))->toBe('code');
});
