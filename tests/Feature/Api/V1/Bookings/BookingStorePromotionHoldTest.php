<?php

use App\Enums\PromotionRedemptionStatus;
use App\Models\Booking;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\PromotionRedemption;
use App\Models\TyreVariant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * `POST /api/v1/bookings`'s promo-stock hold creation — see
 * docs/architecture/05-promotions-pricing.md's "Promo stock-limit
 * enforcement" section: the hold's stock re-check + insert happens inside
 * the same transaction as the booking, only stock-limited promotions go
 * through the `Cache::lock()` gate, and stock exhaustion degrades the
 * booking (promo simply not applied) rather than failing checkout.
 *
 * **Lock-scoping fix (security-agent review, 2026-09-23):** the promo-stock
 * `Cache::lock` is acquired in `BookingController::acquirePromotionLocks()`
 * *before* `DB::transaction()` opens and released in an outer `finally`
 * *after* it returns (commits) — mirroring exactly how the pre-existing
 * technician-slot `$lock` two blocks above it in the same method already
 * wraps its own transaction (see `BookingLockConcurrencyTest.php`'s
 * docblock for that established shape). The original version nested the
 * lock's acquire/release *inside* the transaction closure
 * (`applyPromotionHolds()`), which let the lock's `finally` release it
 * before the transaction actually committed — a concurrent request could
 * then acquire the now-free lock and run its own stock re-check while the
 * first request's `PromotionRedemption` insert was still uncommitted and
 * therefore invisible to it, oversell-ing `stock_limit` under genuine
 * concurrent timing. A literal two-connection test proving that exact
 * window isn't reproducible inside this suite's single PHPUnit process —
 * `RefreshDatabase` wraps every test (including every nested
 * `DB::transaction()` call) in one outer transaction that never truly
 * commits until teardown, so there is no observable "still mid-transaction"
 * moment to assert against from inside the same process; this is the same
 * limitation `BookingLockConcurrencyTest.php`'s own docblock documents for
 * the technician-slot lock, not a gap specific to this fix. Verified
 * instead by: (1) the code-structure citation above, matching this
 * project's established pattern for this exact class of untestable-race
 * fix; (2) every test below exercising the corrected code path end-to-end
 * through the real HTTP flow — a broken refactor (wrong `use()` capture,
 * a lock never released, a deadlock) would fail or hang these, not just
 * the narrow race itself; and (3) the explicit no-lock-leak assertion at
 * the bottom of this file.
 */
beforeEach(function () {
    seedDurationRules();
    Queue::fake();
});

it('creates a held PromotionRedemption for a stock-limited promotion that still has room', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->withStockLimit(10)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, [
            'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1, 'position' => 'all']],
        ]));

    $response->assertCreated();
    $booking = Booking::query()->findOrFail($response->json('data.id'));

    $redemption = PromotionRedemption::query()->where('booking_id', $booking->id)->first();
    expect($redemption)->not->toBeNull();
    expect($redemption->promotion_id)->toBe($promotion->id);
    expect($redemption->status)->toBe(PromotionRedemptionStatus::Held);
    expect($redemption->hold_expires_at->equalTo($booking->hold_expires_at))->toBeTrue();
    expect($redemption->discount_amount)->toBe(2000);
});

it('creates a held PromotionRedemption for a non-stock-limited promotion without needing a lock', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->create(['value' => 10]); // no stock_limit
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, [
            'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1, 'position' => 'all']],
        ]));

    $response->assertCreated();
    $booking = Booking::query()->findOrFail($response->json('data.id'));

    expect(PromotionRedemption::query()->where('booking_id', $booking->id)->where('promotion_id', $promotion->id)->exists())->toBeTrue();
});

it('does not apply a stock-limited promotion once its stock is exhausted, but the booking still succeeds', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->withStockLimit(1)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    // Exhaust the single unit of stock via an existing held redemption on
    // another booking.
    $otherBooking = Booking::factory()->create();
    PromotionRedemption::factory()->create([
        'promotion_id' => $promotion->id,
        'booking_id' => $otherBooking->id,
        'quantity' => 1,
        'status' => PromotionRedemptionStatus::Held,
    ]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, [
            'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1, 'position' => 'all']],
        ]));

    $response->assertCreated();
    $booking = Booking::query()->findOrFail($response->json('data.id'));

    expect(PromotionRedemption::query()->where('booking_id', $booking->id)->exists())->toBeFalse();
});

/**
 * Regression coverage for the quantity-aware stock_limit fix (2026-09-22):
 * the authoritative, lock-protected re-check inside
 * `BookingController::applyPromotionHolds()` must account for *this*
 * booking's own requested quantity, not just whether existing consumption
 * alone is under `stock_limit` — otherwise a multi-unit request against a
 * near-exhausted allocation could oversell it.
 */
it('does not apply a stock-limited promotion when this booking\'s own requested quantity would push existing consumption past the limit, even though existing consumption alone is still under it', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->withStockLimit(3)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    // Existing consumption (2) is still under stock_limit (3).
    $otherBooking = Booking::factory()->create();
    PromotionRedemption::factory()->create([
        'promotion_id' => $promotion->id,
        'booking_id' => $otherBooking->id,
        'quantity' => 2,
        'status' => PromotionRedemptionStatus::Held,
    ]);

    // This booking wants 2 more units -> 2 + 2 = 4 > 3, must be rejected.
    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, [
            'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 2, 'position' => 'all']],
        ]));

    $response->assertCreated();
    $booking = Booking::query()->findOrFail($response->json('data.id'));

    expect(PromotionRedemption::query()->where('booking_id', $booking->id)->exists())->toBeFalse();
});

it('does not apply a stock-limited promotion when its lock cannot be acquired, but the booking still succeeds', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->withStockLimit(10)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    // Hold the promo-stock lock for the whole request so the controller's
    // own Cache::lock()->get() call fails.
    $lock = Cache::lock("promo-hold:{$promotion->id}", 10);
    $lock->get();

    try {
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/bookings', storeBookingPayload($zone, [
                'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1, 'position' => 'all']],
            ]));

        $response->assertCreated();
        $booking = Booking::query()->findOrFail($response->json('data.id'));

        expect(PromotionRedemption::query()->where('booking_id', $booking->id)->exists())->toBeFalse();
    } finally {
        $lock->release();
    }
});

it('cascade-releases held promo redemptions when a booking is explicitly cancelled', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->withStockLimit(10)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, [
            'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1, 'position' => 'all']],
        ]));

    $response->assertCreated();
    $booking = Booking::query()->findOrFail($response->json('data.id'));
    $manageToken = $response->json('data.manage_token');

    $redemption = PromotionRedemption::query()->where('booking_id', $booking->id)->firstOrFail();
    expect($redemption->status)->toBe(PromotionRedemptionStatus::Held);

    $cancelResponse = $this->withHeader('X-Booking-Manage-Token', $manageToken)
        ->postJson("/api/v1/bookings/{$booking->id}/cancel");

    $cancelResponse->assertOk();
    expect($redemption->fresh()->status)->toBe(PromotionRedemptionStatus::Released);
    expect($redemption->fresh()->hold_expires_at)->toBeNull();
});

/**
 * No-lock-leak coverage for the rescoped promo-stock lock (see this file's
 * class docblock): moving the acquire/release out to wrap the whole
 * transaction must not leave the lock held past the request — the exact
 * same lock key must be freely acquirable again immediately afterward,
 * proving `acquirePromotionLocks()`'s locks are genuinely released via the
 * outer `finally` in `BookingController::store()`, not leaked by the
 * restructuring.
 */
it('releases the promo-stock lock once the request completes, leaving it free for a subsequent request', function () {
    ['zone' => $zone] = bookableFixture();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->withStockLimit(10)->create(['value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, [
            'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1, 'position' => 'all']],
        ]));

    $response->assertCreated();

    $probe = Cache::lock("promo-hold:{$promotion->id}", 5);
    expect($probe->get())->toBeTrue();
    $probe->release();
});
