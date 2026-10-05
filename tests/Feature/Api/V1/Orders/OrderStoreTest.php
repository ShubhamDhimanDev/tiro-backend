<?php

use App\Contracts\Payments\PaymentGateway;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Address;
use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Suburb;
use App\Models\TyreVariant;
use App\Services\Payments\PayPalPaymentGateway;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakePayPalPaymentGateway;

/**
 * `POST /api/v1/orders` — see docs/architecture/02-api-contract.md's "Cart,
 * Checkout & Payment endpoints" section.
 */
beforeEach(function () {
    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);
});

/**
 * @return array{booking: Booking, variant: TyreVariant, suburb: Suburb}
 */
function orderableBookingFixture(array $bookingOverrides = []): array
{
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);
    $booking = Booking::factory()->create(array_merge([
        'manage_token_hash' => Booking::hashManageToken('guest-token-123'),
    ], $bookingOverrides));
    BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 4]);
    $suburb = Suburb::factory()->create();

    return compact('booking', 'variant', 'suburb');
}

function orderPayload(Booking $booking, Suburb $suburb, array $overrides = []): array
{
    return array_merge([
        'booking_id' => $booking->id,
        'customer' => ['name' => 'Jane Citizen', 'email' => 'jane@example.com', 'mobile' => '+61411222333'],
        'address' => [
            'suburb_id' => $suburb->id,
            'line1' => '12 Example St',
            'line2' => null,
            'lat' => -37.8,
            'lng' => 144.9,
            'access_instructions' => 'Park in driveway',
        ],
    ], $overrides);
}

it('creates a guest order + Stripe PaymentIntent + guest order_token in one call', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $response->assertCreated();
    expect($response->json('data.status'))->toBe('pending_payment');
    expect($response->json('data.payment_status'))->toBe('pending');
    expect($response->json('data.subtotal'))->toBe(75600);
    expect($response->json('data.tax_total'))->toBe(6873);
    expect($response->json('data.grand_total'))->toBe(75600);
    expect($response->json('data.order_token_issued'))->toBeTrue();
    expect($response->json('data.order_token'))->toBeString();
    expect($response->json('data.payment.gateway'))->toBe('stripe');
    expect($response->json('data.payment.client_secret'))->toBeString();

    $order = Order::query()->findOrFail($response->json('data.id'));
    expect($order->booking_id)->toBe($booking->id);
    expect($order->customer_id)->not->toBeNull();
    expect($order->status)->toBe(OrderStatus::PendingPayment);
    expect($order->payment_status)->toBe(PaymentStatus::Pending);
    expect($order->lineItems)->toHaveCount(1);
    expect($order->guest_token_hash)->not->toBeNull();
    expect($order->guestTokenMatches($response->json('data.order_token')))->toBeTrue();

    $payment = Payment::query()->where('order_id', $order->id)->sole();
    expect($payment->type)->toBe(PaymentType::Charge);
    expect($payment->status)->toBe(PaymentTransactionStatus::Pending);
    expect($payment->amount)->toBe(75600);
    expect($payment->gateway_reference)->toStartWith('pi_');

    $booking->refresh();
    expect($booking->order_id)->toBe($order->id);
    expect($booking->address_id)->toBe($order->address_id);
    // booking.status is untouched by order creation — only the webhook,
    // on payment success, moves it to confirmed.
    expect($booking->status)->toBe(BookingStatus::PendingHold);

    expect($this->gateway->createdIntents)->toHaveCount(1);
});

it('replays the identical response for a repeated Idempotency-Key instead of creating a second order or PaymentIntent', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();
    $key = (string) Str::uuid();

    $first = $this->withHeader('Idempotency-Key', $key)
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $second = $this->withHeader('Idempotency-Key', $key)
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $first->assertCreated();
    $second->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect($second->json('data.order_token'))->toBe($first->json('data.order_token'));
    expect(Order::query()->count())->toBe(1);
    expect($this->gateway->createdIntents)->toHaveCount(1);
    // client_secret is re-fetched, not regenerated, on replay.
    expect($this->gateway->retrievedIntentIds)->not->toBeEmpty();
});

/**
 * Adapted from `BookingStoreTest.php`'s "closes the true concurrent-insert
 * race for a shared Idempotency-Key..." test — see that test's docblock for
 * the full reasoning on why a real two-connection race can't be reproduced
 * inside RefreshDatabase's single wrapping transaction (it deadlocks against
 * itself), and why this decouples the two things that would happen
 * atomically under real concurrency instead: (1) this request's own
 * `Order::create()` failing with the exact exception type MySQL raises for a
 * duplicate `idempotency_key` (`UniqueConstraintViolationException`,
 * constructed directly rather than provoked), and (2) the competing Order —
 * along with the Payment row `OrderController::store()`'s catch handler
 * requires to exist for a successful replay — becoming committed and
 * visible on this same connection once this request's own transaction has
 * actually rolled back (`TransactionRolledBack`, fired post-rollback).
 *
 * The winning row shares this request's `booking_id` (orders.booking_id is
 * itself unique — a real race is necessarily for the same booking, not a
 * different one) and idempotency_key, exactly what two genuinely concurrent
 * submissions of the same checkout would produce.
 */
it('closes the true concurrent-insert race for a shared Idempotency-Key by returning the committed winner instead of a raw 500', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();
    $key = (string) Str::uuid();

    $winningGatewayReference = 'pi_'.Str::random(24);
    $committedWinner = false;

    Event::listen(TransactionRolledBack::class, function () use (&$committedWinner, $key, $booking, $suburb, $winningGatewayReference): void {
        if ($committedWinner) {
            return;
        }

        $committedWinner = true;

        $winnerCustomer = Customer::factory()->create();
        $winnerAddress = Address::factory()->create(['suburb_id' => $suburb->id]);

        $winnerOrderId = DB::table('orders')->insertGetId([
            'order_number' => 'TMS-'.now()->format('Ymd').'-9999',
            'customer_id' => $winnerCustomer->id,
            'booking_id' => $booking->id,
            'address_id' => $winnerAddress->id,
            'status' => OrderStatus::PendingPayment->value,
            'payment_status' => PaymentStatus::Pending->value,
            'subtotal' => 75600,
            'discount_total' => 0,
            'tax_total' => 6873,
            'service_fee_total' => 0,
            'grand_total' => 75600,
            'currency' => 'AUD',
            'idempotency_key' => $key,
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'order_id' => $winnerOrderId,
            'type' => PaymentType::Charge->value,
            'gateway' => 'stripe',
            'method' => 'card',
            'status' => PaymentTransactionStatus::Pending->value,
            'amount' => 75600,
            'gateway_reference' => $winningGatewayReference,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    Order::creating(function (Order $model) use ($key): void {
        if ($model->idempotency_key !== $key) {
            return;
        }

        $previous = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$key}' for key 'orders.idempotency_key'");
        $previous->errorInfo = ['23000', 1062, "Duplicate entry '{$key}' for key 'orders.idempotency_key'"];

        throw new UniqueConstraintViolationException('mysql', 'insert into `orders` (`idempotency_key`, ...) values (?, ...)', [], $previous);
    });

    $response = $this->withHeader('Idempotency-Key', $key)
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $response->assertCreated();
    expect(Order::query()->count())->toBe(1);

    $winner = Order::query()->sole();
    expect($winner->idempotency_key)->toBe($key);
    expect($winner->booking_id)->toBe($booking->id);
    expect($response->json('data.id'))->toBe($winner->id);
    expect($response->json('data.payment.gateway'))->toBe('stripe');

    $payment = Payment::query()->where('order_id', $winner->id)->sole();
    expect($payment->gateway_reference)->toBe($winningGatewayReference);
});

it('returns 409 (not a validation error) when the booking hold has expired', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture([
        'hold_expires_at' => now()->subMinute(),
    ]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $response->assertStatus(409);
    expect($response->json())->not->toHaveKey('errors');
    expect(Order::query()->count())->toBe(0);
});

it('returns 409 when the booking is no longer pending_hold', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture([
        'status' => BookingStatus::Cancelled,
        'hold_expires_at' => null,
    ]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $response->assertStatus(409);
});

it('403s without a valid owner/manage token', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $response->assertStatus(403);
    expect(Order::query()->count())->toBe(0);
});

it('404s for a nonexistent booking_id', function () {
    $suburb = Suburb::factory()->create();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/orders', orderPayload(Booking::factory()->make(['id' => 999999]), $suburb));

    $response->assertStatus(404);
});

it('422s without the Idempotency-Key header', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();

    $response = $this->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $response->assertStatus(422);
});

it('422s when customer.mobile is not in E.164 shape (Phase 7 fix)', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();

    $response = $this->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb, [
            'customer' => ['name' => 'Jane Citizen', 'email' => 'jane@example.com', 'mobile' => '0411222333'],
        ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['customer.mobile']);
});

it('accepts a valid E.164 customer.mobile', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();

    $response = $this->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb, [
            'customer' => ['name' => 'Jane Citizen', 'email' => 'jane@example.com', 'mobile' => '+61411222333'],
        ]));

    $response->assertCreated();
});

it('creates an authenticated customer order without issuing an order_token', function () {
    $customer = Customer::factory()->activated()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);
    $booking = Booking::factory()->create(['customer_id' => $customer->id]);
    BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 4]);
    $suburb = Suburb::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $response->assertCreated();
    expect($response->json('data.order_token_issued'))->toBeFalse();
    expect($response->json('data'))->not->toHaveKey('order_token');

    $order = Order::query()->findOrFail($response->json('data.id'));
    expect($order->customer_id)->toBe($customer->id);
    expect($order->guest_token_hash)->toBeNull();
});

it('derives the address postcode from the linked suburb rather than the request body', function () {
    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $response->assertCreated();

    $order = Order::query()->findOrFail($response->json('data.id'));
    expect($order->address->postcode)->toBe($suburb->postcode);
});

/**
 * PayPal-as-second-gateway coverage — see
 * docs/architecture/03-integrations.md's PayPal section. `PAYMENT_GATEWAY`
 * switches which concrete gateway `OrderController::store()`'s active
 * `PaymentGateway::class` singleton resolves to; here it's bound directly to
 * `FakePayPalPaymentGateway` (same pattern `beforeEach()` above already uses
 * for Stripe), so `config()` only needs to reflect the *selector* value the
 * controller itself reads to decide `Payment.gateway`/the response shape.
 */
it('creates a guest order + PayPal Order + paypal_order_id in one call when PAYMENT_GATEWAY=paypal', function () {
    config(['services.payment_gateway' => 'paypal']);
    $paypalGateway = new FakePayPalPaymentGateway;
    $this->app->instance(PayPalPaymentGateway::class, $paypalGateway);
    $this->app->instance(PaymentGateway::class, $paypalGateway);

    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $response->assertCreated();
    expect($response->json('data.payment.gateway'))->toBe('paypal');
    expect($response->json('data.payment.client_secret'))->toBeNull();
    expect($response->json('data.payment.paypal_order_id'))->toBeString();
    expect($response->json('data.payment.paypal_order_id'))->toStartWith('EC-');

    $payment = Payment::query()->where('order_id', $response->json('data.id'))->sole();
    expect($payment->gateway)->toBe(App\Enums\PaymentGateway::PayPal);
    expect($payment->gateway_reference)->toBe($response->json('data.payment.paypal_order_id'));

    expect($paypalGateway->createdOrders)->toHaveCount(1);
});

it('replays the PayPal order_id unchanged (no second PayPal Order call) on an Idempotency-Key replay', function () {
    config(['services.payment_gateway' => 'paypal']);
    $paypalGateway = new FakePayPalPaymentGateway;
    $this->app->instance(PayPalPaymentGateway::class, $paypalGateway);
    $this->app->instance(PaymentGateway::class, $paypalGateway);

    ['booking' => $booking, 'suburb' => $suburb] = orderableBookingFixture();
    $key = (string) Str::uuid();

    $first = $this->withHeader('Idempotency-Key', $key)
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $second = $this->withHeader('Idempotency-Key', $key)
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', orderPayload($booking, $suburb));

    $first->assertCreated();
    $second->assertCreated();
    expect($second->json('data.payment.paypal_order_id'))->toBe($first->json('data.payment.paypal_order_id'));
    expect($paypalGateway->createdOrders)->toHaveCount(1);
    // Unlike Stripe's client_secret re-fetch, nothing needs re-fetching for
    // a PayPal replay — the order id is simply re-surfaced unchanged.
    expect($paypalGateway->retrievedOrderIds)->toBeEmpty();
});
