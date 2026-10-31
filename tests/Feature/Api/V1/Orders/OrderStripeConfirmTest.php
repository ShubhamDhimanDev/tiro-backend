<?php

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Support\Str;
use Tests\Support\FakeStripePaymentGateway;

/**
 * `POST /api/v1/orders/{order}/stripe-confirm` — the synchronous path that
 * confirms an order as soon as Stripe reports the PaymentIntent `succeeded`,
 * without waiting on the webhook. `webhookFixture()` lives in `tests/Pest.php`.
 */
beforeEach(function () {
    $this->stripe = new FakeStripePaymentGateway;
    $this->app->instance(StripePaymentGateway::class, $this->stripe);
});

/**
 * @return array{order: Order, booking: Booking, payment: Payment}
 */
function stripeConfirmFixture(array $bookingOverrides = []): array
{
    $fixture = webhookFixture($bookingOverrides);
    $fixture['order']->forceFill(['guest_token_hash' => Order::hashGuestToken('order-token-123')])->save();

    return $fixture;
}

it('confirms booking+order+payment when Stripe reports the PaymentIntent succeeded, without any webhook', function () {
    ['booking' => $booking, 'order' => $order, 'payment' => $payment] = stripeConfirmFixture();

    $response = $this->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/stripe-confirm");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('confirmed');
    expect($response->json('data.payment_status'))->toBe('paid');
    expect($response->json('data'))->not->toHaveKey('payment');

    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()->hold_expires_at)->toBeNull();
    expect($this->stripe->retrievedIntentIds)->toBe([$payment->gateway_reference]);
});

it('resolves Payment.method from Stripe\'s own report instead of the placeholder', function () {
    ['order' => $order, 'payment' => $payment] = stripeConfirmFixture();

    $paymentMethodId = 'pm_'.Str::random(24);
    $this->stripe->intentPaymentMethodIds[$payment->gateway_reference] = $paymentMethodId;
    $this->stripe->paymentMethodOverrides[$paymentMethodId] = ['type' => 'card', 'walletType' => 'apple_pay'];

    $this->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/stripe-confirm")
        ->assertOk();

    expect($payment->fresh()->method)->toBe(PaymentMethod::ApplePay);
});

it('leaves the order pending when Stripe reports a non-succeeded status such as processing', function (string $stripeStatus) {
    ['booking' => $booking, 'order' => $order, 'payment' => $payment] = stripeConfirmFixture();
    $this->stripe->intentStatusOverrides[$payment->gateway_reference] = $stripeStatus;

    $response = $this->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/stripe-confirm");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('pending_payment');
    expect($response->json('data.payment_status'))->toBe('pending');

    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    expect($booking->fresh()->status)->toBe(BookingStatus::PendingHold);
})->with(['processing', 'requires_action', 'requires_payment_method']);

it('does not call Stripe again when the charge is already succeeded (idempotent replay)', function () {
    ['order' => $order, 'payment' => $payment] = stripeConfirmFixture();
    $payment->forceFill(['status' => PaymentTransactionStatus::Succeeded])->save();

    $this->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/stripe-confirm")
        ->assertOk();

    expect($this->stripe->retrievedIntentIds)->toBeEmpty();
});

it('sets Order.status = refund_required when the booking hold already expired', function () {
    ['order' => $order, 'booking' => $booking, 'payment' => $payment] = stripeConfirmFixture([
        'status' => BookingStatus::Expired,
        'hold_expires_at' => null,
    ]);

    $this->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/stripe-confirm")
        ->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::RefundRequired);
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect($booking->fresh()->status)->toBe(BookingStatus::Expired);
});

it('422s for an order that was not paid through Stripe', function () {
    ['order' => $order, 'payment' => $payment] = stripeConfirmFixture();
    $payment->forceFill(['gateway' => PaymentGateway::PayPal])->save();

    $this->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/stripe-confirm")
        ->assertStatus(422);

    expect($this->stripe->retrievedIntentIds)->toBeEmpty();
});

it('403s without a valid owner/order token and never calls Stripe', function () {
    ['order' => $order, 'payment' => $payment] = stripeConfirmFixture();

    $this->postJson("/api/v1/orders/{$order->id}/stripe-confirm")->assertStatus(403);
    $this->withHeader('X-Order-Token', 'wrong-token')
        ->postJson("/api/v1/orders/{$order->id}/stripe-confirm")
        ->assertStatus(403);

    expect($this->stripe->retrievedIntentIds)->toBeEmpty();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
});

it('404s for a nonexistent order', function () {
    $this->postJson('/api/v1/orders/999999/stripe-confirm')->assertStatus(404);
});
