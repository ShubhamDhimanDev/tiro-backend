<?php

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\FakeStripePaymentGateway;

/**
 * `POST /api/v1/webhooks/stripe` — see
 * docs/architecture/02-api-contract.md's "Webhook security & replay-safety"
 * section.
 *
 * `stripeEvent()`/`signedStripePayload()`/`postStripeWebhook()`/
 * `webhookFixture()` live in `tests/Pest.php`, not here — same
 * "shared-helper-must-be-globally-loaded" reasoning as `actingSuperAdmin()`,
 * since `StripeWebhookPromotionTest.php` also needs them and Pest test
 * files can legitimately run in isolation.
 *
 * Binds the fake against the concrete `StripePaymentGateway::class` —
 * `StripeWebhookController` now type-hints that class directly, never the
 * generic `PaymentGateway` interface, per
 * docs/architecture/03-integrations.md's PayPal section, point 3.
 */
beforeEach(function () {
    $this->gateway = new FakeStripePaymentGateway;
    $this->app->instance(StripePaymentGateway::class, $this->gateway);
});

it('400s and does not process an unverified payload', function () {
    ['order' => $order, 'payment' => $payment] = webhookFixture();

    $response = postStripeWebhook(
        stripeEvent('payment_intent.succeeded', ['id' => $payment->gateway_reference, 'object' => 'payment_intent', 'status' => 'succeeded']),
        signatureOverride: 't=1234567890,v1=deadbeef',
    );

    $response->assertStatus(400);
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('confirms booking+order+payment when the hold is still live', function () {
    ['booking' => $booking, 'order' => $order, 'payment' => $payment] = webhookFixture();

    $response = postStripeWebhook(stripeEvent('payment_intent.succeeded', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
    ]));

    $response->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()->hold_expires_at)->toBeNull();
});

it('resolves Payment.method from Stripe\'s own report on payment_intent.succeeded, not the placeholder default', function (string $stripeType, ?string $stripeWalletType, PaymentMethod $expected) {
    ['payment' => $payment] = webhookFixture();
    // PaymentFactory's default — every case here must prove the webhook
    // actually resolved the method, not merely left the placeholder alone.
    expect($payment->method)->toBe(PaymentMethod::Card);

    $paymentMethodId = 'pm_'.Str::random(24);
    $this->gateway->paymentMethodOverrides[$paymentMethodId] = ['type' => $stripeType, 'walletType' => $stripeWalletType];

    $response = postStripeWebhook(stripeEvent('payment_intent.succeeded', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
        'payment_method' => $paymentMethodId,
    ]));

    $response->assertOk();
    expect($this->gateway->retrievedPaymentMethodIds)->toBe([$paymentMethodId]);
    expect($payment->fresh()->method)->toBe($expected);
})->with([
    'card (no wallet)' => ['card', null, PaymentMethod::Card],
    'Apple Pay (card.wallet.type)' => ['card', 'apple_pay', PaymentMethod::ApplePay],
    'Google Pay (card.wallet.type)' => ['card', 'google_pay', PaymentMethod::GooglePay],
    'Afterpay (top-level type, Stripe spells it afterpay_clearpay)' => ['afterpay_clearpay', null, PaymentMethod::Afterpay],
]);

it('surfaces an unrecognized Stripe payment method type loudly instead of silently defaulting', function () {
    ['payment' => $payment] = webhookFixture();

    $paymentMethodId = 'pm_'.Str::random(24);
    $this->gateway->paymentMethodOverrides[$paymentMethodId] = ['type' => 'paypal', 'walletType' => null];

    $response = postStripeWebhook(stripeEvent('payment_intent.succeeded', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
        'payment_method' => $paymentMethodId,
    ]));

    // Uncaught RuntimeException from PaymentMethod::fromStripe()'s
    // default => throw arm — a 500, not a swallowed/silently-wrong write.
    $response->assertStatus(500);
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    expect($payment->fresh()->method)->toBe(PaymentMethod::Card);
});

it('leaves Payment.method untouched when the succeeded PaymentIntent has no payment_method (shouldn\'t happen per Stripe\'s own contract)', function () {
    ['payment' => $payment] = webhookFixture();

    $response = postStripeWebhook(stripeEvent('payment_intent.succeeded', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
    ]));

    $response->assertOk();
    expect($this->gateway->retrievedPaymentMethodIds)->toBe([]);
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect($payment->fresh()->method)->toBe(PaymentMethod::Card);
});

it('sets Order.status = refund_required and writes an AuditLog row when the hold already expired', function () {
    ['booking' => $booking, 'order' => $order, 'payment' => $payment] = webhookFixture([
        'status' => BookingStatus::Expired,
        'hold_expires_at' => null,
    ]);

    $response = postStripeWebhook(stripeEvent('payment_intent.succeeded', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
    ]));

    $response->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect($order->fresh()->status)->toBe(OrderStatus::RefundRequired);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    // Booking is deliberately not touched — the slot may already be given away.
    expect($booking->fresh()->status)->toBe(BookingStatus::Expired);

    $audit = AuditLog::query()->where('auditable_type', Order::class)->where('auditable_id', $order->id)->sole();
    expect($audit->action)->toBe('orders.payment_confirmed_after_hold_expired');
    expect($audit->actor_id)->toBeNull();
});

it('marks the payment/order failed on payment_intent.payment_failed and leaves the booking hold untouched', function () {
    ['booking' => $booking, 'order' => $order, 'payment' => $payment] = webhookFixture();

    $response = postStripeWebhook(stripeEvent('payment_intent.payment_failed', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'status' => 'requires_payment_method',
    ]));

    $response->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Failed);
    expect($order->fresh()->status)->toBe(OrderStatus::PaymentFailed);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Failed);
    expect($booking->fresh()->status)->toBe(BookingStatus::PendingHold);
    expect($booking->fresh()->hold_expires_at)->not->toBeNull();
});

it('200s and makes no changes when payment_intent.payment_failed references a gateway_reference this app does not recognize', function () {
    ['order' => $order, 'payment' => $payment] = webhookFixture();

    $response = postStripeWebhook(stripeEvent('payment_intent.payment_failed', [
        'id' => 'pi_unrecognized_'.Str::random(16),
        'object' => 'payment_intent',
        'status' => 'requires_payment_method',
    ]));

    $response->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('short-circuits a redelivered event.id without reprocessing (cache-based dedup)', function () {
    ['order' => $order, 'payment' => $payment] = webhookFixture();

    $event = stripeEvent('payment_intent.succeeded', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
    ]);

    postStripeWebhook($event)->assertOk();
    expect(Cache::has("stripe-webhook-event:{$event['id']}"))->toBeTrue();

    // Redeliver the identical event.id — short-circuited before any
    // reprocessing, still 200.
    postStripeWebhook($event)->assertOk();

    expect(Order::query()->where('id', $order->id)->count())->toBe(1);
});

it('reconciles a refund.updated event against an existing refund Payment row (backstop, not primary trigger)', function () {
    ['order' => $order] = webhookFixture();
    $refund = Payment::factory()->refund()->create([
        'order_id' => $order->id,
        'status' => PaymentTransactionStatus::Pending,
        'gateway_reference' => 're_reconcile_test',
    ]);

    $response = postStripeWebhook(stripeEvent('refund.updated', [
        'id' => 're_reconcile_test',
        'object' => 'refund',
        'status' => 'succeeded',
        'amount' => $refund->amount,
    ]));

    $response->assertOk();
    expect($refund->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
});

/**
 * `charge.refunded` has its own distinct extraction step —
 * `handleRefundReconciliation()` pulls `(array) ($event->data->object->
 * refunds->data ?? [])` off the *charge* object's nested `refunds.data`
 * list, rather than reading the event's own `data.object` directly the way
 * `refund.updated` does — and reconciles every entry in it, not just the
 * first. Two refund objects prove the extraction actually iterates the list
 * rather than only ever touching a single, first-position entry.
 */
it('reconciles every refund in a charge.refunded event\'s nested refunds.data array', function () {
    ['order' => $order] = webhookFixture();
    $refundA = Payment::factory()->refund()->create([
        'order_id' => $order->id,
        'status' => PaymentTransactionStatus::Pending,
        'gateway_reference' => 're_charge_refunded_a',
    ]);
    $refundB = Payment::factory()->refund()->create([
        'order_id' => $order->id,
        'status' => PaymentTransactionStatus::Pending,
        'gateway_reference' => 're_charge_refunded_b',
    ]);

    $response = postStripeWebhook(stripeEvent('charge.refunded', [
        'id' => 'ch_'.Str::random(24),
        'object' => 'charge',
        'refunds' => [
            'object' => 'list',
            'data' => [
                ['id' => 're_charge_refunded_a', 'object' => 'refund', 'status' => 'succeeded', 'amount' => $refundA->amount],
                ['id' => 're_charge_refunded_b', 'object' => 'refund', 'status' => 'succeeded', 'amount' => $refundB->amount],
            ],
        ],
    ]));

    $response->assertOk();
    expect($refundA->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect($refundB->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
});

it('200s and makes no changes when payment_intent.succeeded references a gateway_reference this app does not recognize', function () {
    ['order' => $order, 'payment' => $payment] = webhookFixture();

    $response = postStripeWebhook(stripeEvent('payment_intent.succeeded', [
        'id' => 'pi_unrecognized_'.Str::random(16),
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
    ]));

    $response->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('200s and makes no changes when a refund.updated event references a gateway_reference this app does not recognize', function () {
    ['order' => $order] = webhookFixture();
    $refund = Payment::factory()->refund()->create([
        'order_id' => $order->id,
        'status' => PaymentTransactionStatus::Pending,
        'gateway_reference' => 're_known_test',
    ]);

    $response = postStripeWebhook(stripeEvent('refund.updated', [
        'id' => 're_unrecognized_'.Str::random(16),
        'object' => 'refund',
        'status' => 'succeeded',
        'amount' => $refund->amount,
    ]));

    $response->assertOk();
    expect($refund->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
});

/**
 * `PaymentTransactionStatus::fromStripeStatus()`'s `default => throw` arm —
 * same "importer boundary, deliberately non-exhaustive-provable" convention
 * as `PaymentMethod::fromStripe()`'s own throw arm, tested the same way
 * (see "surfaces an unrecognized Stripe payment method type loudly instead
 * of silently defaulting" above): an unmapped value must surface loudly, not
 * silently default or get swallowed.
 */
it('surfaces an unrecognized Stripe refund status loudly instead of silently defaulting', function () {
    ['order' => $order] = webhookFixture();
    $refund = Payment::factory()->refund()->create([
        'order_id' => $order->id,
        'status' => PaymentTransactionStatus::Pending,
        'gateway_reference' => 're_bogus_status_test',
    ]);

    $response = postStripeWebhook(stripeEvent('refund.updated', [
        'id' => 're_bogus_status_test',
        'object' => 'refund',
        'status' => 'unknown_status',
        'amount' => $refund->amount,
    ]));

    // Uncaught RuntimeException from PaymentTransactionStatus::
    // fromStripeStatus()'s default => throw arm — a 500, not a
    // swallowed/silently-wrong write.
    $response->assertStatus(500);
    expect($refund->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
});

it('does not poison the cache dedup key on a throwing delivery, so a redelivery of the identical event actually reprocesses it', function () {
    ['payment' => $payment] = webhookFixture();

    $paymentMethodId = 'pm_'.Str::random(24);
    $this->gateway->paymentMethodOverrides[$paymentMethodId] = ['type' => 'paypal', 'walletType' => null];

    $event = stripeEvent('payment_intent.succeeded', [
        'id' => $payment->gateway_reference,
        'object' => 'payment_intent',
        'amount' => $payment->amount,
        'currency' => 'aud',
        'status' => 'succeeded',
        'payment_method' => $paymentMethodId,
    ]);

    // First delivery throws on the unmapped payment-method type — a 500 —
    // and, crucially, must NOT have marked the event.id as handled.
    postStripeWebhook($event)->assertStatus(500);
    expect(Cache::has("stripe-webhook-event:{$event['id']}"))->toBeFalse();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);

    // Simulate the mapping gap being resolved before Stripe's automatic
    // redelivery of the identical event arrives.
    $this->gateway->paymentMethodOverrides[$paymentMethodId] = ['type' => 'card', 'walletType' => null];

    // Redelivery of the identical event.id must actually reprocess it —
    // not be silently short-circuited to a bare 200 with the payment still
    // stuck pending (the exact gap this test guards against).
    postStripeWebhook($event)->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect(Cache::has("stripe-webhook-event:{$event['id']}"))->toBeTrue();
});

it('ignores an event type it does not handle, still 200', function () {
    $response = postStripeWebhook(stripeEvent('customer.created', ['id' => 'cus_test123', 'object' => 'customer']));

    $response->assertOk();
});
