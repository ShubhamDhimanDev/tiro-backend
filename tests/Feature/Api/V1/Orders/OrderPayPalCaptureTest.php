<?php

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PayPalPaymentGateway;
use Illuminate\Support\Str;
use Tests\Support\FakePayPalPaymentGateway;

/**
 * `POST /api/v1/orders/{order}/paypal-capture` — see
 * docs/architecture/02-api-contract.md's
 * `POST /api/v1/orders/{order}/paypal-capture` section.
 */
beforeEach(function () {
    $this->paypal = new FakePayPalPaymentGateway;
    $this->app->instance(PayPalPaymentGateway::class, $this->paypal);
});

/**
 * @return array{order: Order, booking: Booking, payment: Payment}
 */
function paypalOrderFixture(array $bookingOverrides = [], array $paymentOverrides = []): array
{
    $booking = Booking::factory()->create(array_merge([
        'manage_token_hash' => Booking::hashManageToken('guest-token-123'),
    ], $bookingOverrides));

    $order = Order::factory()->create(array_merge([
        'booking_id' => $booking->id,
        'status' => OrderStatus::PendingPayment,
        'payment_status' => PaymentStatus::Pending,
        'guest_token_hash' => Order::hashGuestToken('order-token-123'),
    ]));

    $payment = Payment::factory()->create(array_merge([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'gateway' => PaymentGateway::PayPal,
        'method' => PaymentMethod::Card,
        'status' => PaymentTransactionStatus::Pending,
        'gateway_reference' => 'EC-'.Str::upper(Str::random(17)),
        'amount' => $order->grand_total,
    ], $paymentOverrides));

    return compact('order', 'booking', 'payment');
}

it('captures the order, confirms booking+order+payment, and returns the updated order payload', function () {
    ['order' => $order, 'booking' => $booking, 'payment' => $payment] = paypalOrderFixture([
        'hold_expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/paypal-capture");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('confirmed');
    expect($response->json('data.payment_status'))->toBe('paid');
    expect($response->json('data'))->not->toHaveKey('payment');
    expect($response->json('data'))->not->toHaveKey('order_token');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect($payment->method)->toBe(PaymentMethod::PayPal);
    expect($payment->gateway_capture_reference)->not->toBeNull();
    expect($payment->gateway_capture_reference)->not->toBe($payment->gateway_reference);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Confirmed);
    expect($order->payment_status)->toBe(PaymentStatus::Paid);

    $booking->refresh();
    expect($booking->status->value)->toBe('confirmed');

    expect($this->paypal->capturedOrders)->toHaveCount(1);
    expect($this->paypal->capturedOrders[0]['orderId'])->toBe($payment->gateway_reference);
});

it('resolves Payment.method from the capture response\'s payment_source, not a placeholder', function () {
    ['order' => $order, 'payment' => $payment] = paypalOrderFixture(['hold_expires_at' => now()->addMinutes(10)]);
    $this->paypal->paymentSourceTypeOverrides[$payment->gateway_reference] = 'card';

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/paypal-capture");

    $response->assertOk();
    expect($payment->fresh()->method)->toBe(PaymentMethod::Card);
});

it('does not call PayPal a second time when the charge is already succeeded (idempotent replay)', function () {
    ['order' => $order, 'payment' => $payment] = paypalOrderFixture(['hold_expires_at' => now()->addMinutes(10)], [
        'status' => PaymentTransactionStatus::Succeeded,
        'gateway_capture_reference' => '1CA'.Str::upper(Str::random(14)),
    ]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/paypal-capture");

    $response->assertOk();
    expect($this->paypal->capturedOrders)->toBeEmpty();
});

it('marks the payment/order failed and returns 422 when PayPal declines the capture', function () {
    ['order' => $order, 'payment' => $payment] = paypalOrderFixture(['hold_expires_at' => now()->addMinutes(10)]);
    $this->paypal->declineNextCapture[$payment->gateway_reference] = 'INSTRUMENT_DECLINED';

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/paypal-capture");

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('INSTRUMENT_DECLINED');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentTransactionStatus::Failed);
    expect($payment->gateway_capture_reference)->toBeNull();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::PaymentFailed);
    expect($order->payment_status)->toBe(PaymentStatus::Failed);

    // The booking hold is left untouched — same posture as Stripe's
    // payment_intent.payment_failed handling.
    expect($order->booking->status->value)->toBe('pending_hold');
});

it('sets Order.status = refund_required when the booking hold already expired before capture', function () {
    ['order' => $order, 'booking' => $booking, 'payment' => $payment] = paypalOrderFixture([
        'status' => BookingStatus::Expired,
        'hold_expires_at' => null,
    ]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/paypal-capture");

    $response->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::RefundRequired);
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
});

it('403s without a valid owner/order token', function () {
    ['order' => $order] = paypalOrderFixture();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/orders/{$order->id}/paypal-capture");

    $response->assertStatus(403);
    expect($this->paypal->capturedOrders)->toBeEmpty();
});

it('404s for a nonexistent order', function () {
    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/orders/999999/paypal-capture');

    $response->assertStatus(404);
});

it('422s without the Idempotency-Key header', function () {
    ['order' => $order] = paypalOrderFixture();

    $response = $this->withHeader('X-Order-Token', 'order-token-123')
        ->postJson("/api/v1/orders/{$order->id}/paypal-capture");

    $response->assertStatus(422);
});
