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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * `POST /api/v1/webhooks/paypal` — see
 * docs/architecture/02-api-contract.md's `POST /api/v1/webhooks/paypal`
 * section. Signature verification is a real server-to-server call (never
 * skipped/faked in production code) — faked here via `Http::fake()`, the
 * standard Laravel test double for this project's `Http`-facade-based
 * integrations (same convention as `MessageMediaClient`'s tests).
 */
const PAYPAL_SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';

/**
 * @param  array<string, mixed>  $resource
 * @return array<string, mixed>
 */
function paypalWebhookEvent(string $eventType, array $resource, ?string $id = null): array
{
    return [
        'id' => $id ?? 'WH-'.Str::random(20),
        'event_type' => $eventType,
        'resource_type' => 'capture',
        'summary' => 'Test event',
        'resource' => $resource,
    ];
}

function fakePayPalWebhookHttp(string $verificationStatus = 'SUCCESS'): void
{
    Http::fake([
        PAYPAL_SANDBOX_BASE_URL.'/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600], 200),
        PAYPAL_SANDBOX_BASE_URL.'/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $verificationStatus], 200),
    ]);
}

/**
 * @return array<string, string>
 */
function paypalWebhookHeaders(): array
{
    return [
        'PAYPAL-TRANSMISSION-ID' => (string) Str::uuid(),
        'PAYPAL-TRANSMISSION-TIME' => now()->toIso8601String(),
        'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert/fake',
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        'PAYPAL-TRANSMISSION-SIG' => base64_encode('fake-signature'),
    ];
}

/**
 * @param  array<string, mixed>  $eventPayload
 */
function postPayPalWebhook(array $eventPayload)
{
    return test()->withHeaders(paypalWebhookHeaders())
        ->call('POST', '/api/v1/webhooks/paypal', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($eventPayload));
}

/**
 * @return array{booking: Booking, order: Order, payment: Payment}
 */
function paypalWebhookFixture(array $bookingOverrides = []): array
{
    $booking = Booking::factory()->create(array_merge([
        'status' => BookingStatus::PendingHold,
        'hold_expires_at' => now()->addMinutes(10),
    ], $bookingOverrides));

    $order = Order::factory()->create([
        'booking_id' => $booking->id,
        'status' => OrderStatus::PendingPayment,
        'payment_status' => PaymentStatus::Pending,
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'gateway' => PaymentGateway::PayPal,
        'status' => PaymentTransactionStatus::Pending,
        'gateway_reference' => 'EC-'.Str::upper(Str::random(17)),
        'amount' => $order->grand_total,
    ]);

    return compact('booking', 'order', 'payment');
}

/**
 * @return array<string, mixed>
 */
function paypalCaptureResource(Payment $payment, string $status = 'COMPLETED', string $paymentSourceType = 'paypal'): array
{
    return [
        'id' => '1CA'.Str::upper(Str::random(14)),
        'status' => $status,
        'amount' => ['currency_code' => 'AUD', 'value' => number_format($payment->amount / 100, 2, '.', '')],
        'payment_source' => [$paymentSourceType => new stdClass],
        'supplementary_data' => ['related_ids' => ['order_id' => $payment->gateway_reference]],
    ];
}

it('400s and does not process an unverified payload', function () {
    fakePayPalWebhookHttp('FAILURE');
    ['order' => $order, 'payment' => $payment] = paypalWebhookFixture();

    $response = postPayPalWebhook(paypalWebhookEvent('PAYMENT.CAPTURE.COMPLETED', paypalCaptureResource($payment)));

    $response->assertStatus(400);
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('confirms booking+order+payment on PAYMENT.CAPTURE.COMPLETED when the hold is still live', function () {
    fakePayPalWebhookHttp();
    ['booking' => $booking, 'order' => $order, 'payment' => $payment] = paypalWebhookFixture();

    $response = postPayPalWebhook(paypalWebhookEvent('PAYMENT.CAPTURE.COMPLETED', paypalCaptureResource($payment)));

    $response->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect($payment->fresh()->method)->toBe(PaymentMethod::PayPal);
    expect($payment->fresh()->gateway_capture_reference)->not->toBeNull();
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('sets Order.status = refund_required when the hold already expired', function () {
    fakePayPalWebhookHttp();
    ['order' => $order, 'payment' => $payment] = paypalWebhookFixture(['status' => BookingStatus::Expired, 'hold_expires_at' => null]);

    $response = postPayPalWebhook(paypalWebhookEvent('PAYMENT.CAPTURE.COMPLETED', paypalCaptureResource($payment)));

    $response->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::RefundRequired);
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
});

it('marks the payment/order failed on PAYMENT.CAPTURE.DENIED and leaves the booking hold untouched', function () {
    fakePayPalWebhookHttp();
    ['booking' => $booking, 'order' => $order, 'payment' => $payment] = paypalWebhookFixture();

    $resource = [
        'id' => '1CA'.Str::upper(Str::random(14)),
        'status' => 'DECLINED',
        'supplementary_data' => ['related_ids' => ['order_id' => $payment->gateway_reference]],
    ];

    $response = postPayPalWebhook(paypalWebhookEvent('PAYMENT.CAPTURE.DENIED', $resource));

    $response->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Failed);
    expect($order->fresh()->status)->toBe(OrderStatus::PaymentFailed);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Failed);
    expect($booking->fresh()->status)->toBe(BookingStatus::PendingHold);
});

it('short-circuits a redelivered event id without reprocessing (cache-based dedup)', function () {
    fakePayPalWebhookHttp();
    ['order' => $order, 'payment' => $payment] = paypalWebhookFixture();

    $event = paypalWebhookEvent('PAYMENT.CAPTURE.COMPLETED', paypalCaptureResource($payment));

    postPayPalWebhook($event)->assertOk();
    expect(Cache::has("paypal-webhook-event:{$event['id']}"))->toBeTrue();

    postPayPalWebhook($event)->assertOk();

    expect(Order::query()->where('id', $order->id)->count())->toBe(1);
});

/**
 * The exact bug class this project already shipped and fixed once for
 * Stripe in Phase 4 (see
 * `.claude/agent-memory/security-agent/feedback_webhook_cache_ordering.md`)
 * — a legitimate handler throw (an unmapped `payment_source` type via
 * `PaymentMethod::fromPayPal()`'s `default => throw`) must NOT leave the
 * event id marked "handled", or PayPal's automatic redelivery of the
 * identical event would be silently short-circuited forever, stranding a
 * genuinely-paid order.
 */
it('does not poison the cache dedup key on a throwing delivery, so a redelivery of the identical event actually reprocesses it', function () {
    fakePayPalWebhookHttp();
    ['payment' => $payment] = paypalWebhookFixture();

    $event = paypalWebhookEvent('PAYMENT.CAPTURE.COMPLETED', paypalCaptureResource($payment, paymentSourceType: 'venmo'));

    postPayPalWebhook($event)->assertStatus(500);
    expect(Cache::has("paypal-webhook-event:{$event['id']}"))->toBeFalse();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);

    // Simulate the mapping gap being resolved before PayPal's automatic
    // redelivery of the identical event arrives — same capture id/order id,
    // now a recognized payment_source.
    $resource = $event['resource'];
    $resource['payment_source'] = ['paypal' => new stdClass];
    $event['resource'] = $resource;

    postPayPalWebhook($event)->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect(Cache::has("paypal-webhook-event:{$event['id']}"))->toBeTrue();
});

it('200s and makes no changes when the resource references a gateway_reference this app does not recognize', function () {
    fakePayPalWebhookHttp();
    ['order' => $order, 'payment' => $payment] = paypalWebhookFixture();

    $resource = paypalCaptureResource($payment);
    $resource['supplementary_data']['related_ids']['order_id'] = 'EC-UNRECOGNIZED';

    $response = postPayPalWebhook(paypalWebhookEvent('PAYMENT.CAPTURE.COMPLETED', $resource));

    $response->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('caches the OAuth token across multiple webhook deliveries instead of re-fetching per request', function () {
    fakePayPalWebhookHttp();
    ['payment' => $paymentA] = paypalWebhookFixture();
    ['payment' => $paymentB] = paypalWebhookFixture();

    postPayPalWebhook(paypalWebhookEvent('PAYMENT.CAPTURE.COMPLETED', paypalCaptureResource($paymentA)))->assertOk();
    postPayPalWebhook(paypalWebhookEvent('PAYMENT.CAPTURE.COMPLETED', paypalCaptureResource($paymentB)))->assertOk();

    Http::assertSentCount(3); // 1 oauth2/token + 2 verify-webhook-signature
});

it('ignores an event type it does not handle, still 200', function () {
    fakePayPalWebhookHttp();

    $response = postPayPalWebhook(paypalWebhookEvent('CHECKOUT.ORDER.APPROVED', ['id' => 'EC-TEST']));

    $response->assertOk();
});
