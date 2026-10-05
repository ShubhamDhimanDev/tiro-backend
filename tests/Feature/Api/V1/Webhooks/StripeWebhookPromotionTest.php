<?php

use App\Enums\PromotionRedemptionStatus;
use App\Enums\Status;
use App\Models\BookingLineItem;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\PromotionRedemption;
use App\Models\TyreVariant;
use App\Services\Payments\StripePaymentGateway;
use Tests\Support\FakeStripePaymentGateway;

/**
 * `POST /api/v1/webhooks/stripe`'s promo-redemption confirmation step — see
 * docs/architecture/05-promotions-pricing.md's "Promo stock-limit
 * enforcement" section, step 5: a `held` `PromotionRedemption` flips to
 * `confirmed` (stamping `order_id`/`redeemed_at`) in the same transaction
 * that confirms `Booking`/`Order`, and `Promotion.usage_count` increments.
 *
 * Binds the fake against the concrete `StripePaymentGateway::class` — see
 * `StripeWebhookTest.php`'s equivalent note.
 */
beforeEach(function () {
    $this->gateway = new FakeStripePaymentGateway;
    $this->app->instance(StripePaymentGateway::class, $this->gateway);
});

it('confirms a held PromotionRedemption and increments usage_count when the booking hold is still live', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->create(['value' => 10, 'usage_count' => 0]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    ['booking' => $booking, 'order' => $order, 'payment' => $payment] = webhookFixture();

    BookingLineItem::factory()->create([
        'booking_id' => $booking->id,
        'tyre_variant_id' => $variant->id,
        'quantity' => 1,
    ]);

    $redemption = PromotionRedemption::factory()->create([
        'promotion_id' => $promotion->id,
        'booking_id' => $booking->id,
        'discount_amount' => 2000,
        'status' => PromotionRedemptionStatus::Held,
        'hold_expires_at' => $booking->hold_expires_at,
    ]);

    $response = postStripeWebhook(stripeEvent('payment_intent.succeeded', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
    ]));

    $response->assertOk();

    $redemption->refresh();
    expect($redemption->status)->toBe(PromotionRedemptionStatus::Confirmed);
    expect($redemption->order_id)->toBe($order->id);
    expect($redemption->redeemed_at)->not->toBeNull();
    expect($redemption->discount_amount)->toBe(2000);
    expect($promotion->fresh()->usage_count)->toBe(1);
});

it('keeps the hold-time discount_amount for a held redemption whose promotion no longer evaluates (e.g. deactivated in the meantime)', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->create(['value' => 10, 'usage_count' => 0]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    ['booking' => $booking, 'order' => $order, 'payment' => $payment] = webhookFixture();

    BookingLineItem::factory()->create([
        'booking_id' => $booking->id,
        'tyre_variant_id' => $variant->id,
        'quantity' => 1,
    ]);

    $redemption = PromotionRedemption::factory()->create([
        'promotion_id' => $promotion->id,
        'booking_id' => $booking->id,
        'discount_amount' => 2000,
        'status' => PromotionRedemptionStatus::Held,
        'hold_expires_at' => $booking->hold_expires_at,
    ]);

    // Deactivate the promotion between order-creation and webhook firing.
    $promotion->forceFill(['status' => Status::Inactive])->save();

    $response = postStripeWebhook(stripeEvent('payment_intent.succeeded', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
    ]));

    $response->assertOk();

    $redemption->refresh();
    expect($redemption->status)->toBe(PromotionRedemptionStatus::Confirmed);
    expect($redemption->discount_amount)->toBe(2000);
});
