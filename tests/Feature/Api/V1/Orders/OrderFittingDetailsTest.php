<?php

use App\Contracts\Payments\PaymentGateway;
use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Suburb;
use App\Models\TyreVariant;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;

/**
 * Checkout wizard extras on `POST /api/v1/orders` (see
 * docs/redesign/api-contract-phase7.md section 7).
 */
beforeEach(function () {
    $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
});

function fittingOrderRequest(array $extra = []): array
{
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);
    $booking = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('guest-token-123')]);
    BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 4]);
    $suburb = Suburb::factory()->create();

    return array_merge([
        'booking_id' => $booking->id,
        'customer' => ['name' => 'Jane Citizen', 'email' => 'Jane@Example.com', 'mobile' => '+61411222333'],
        'address' => ['suburb_id' => $suburb->id, 'line1' => '12 Example St', 'lat' => -37.8, 'lng' => 144.9],
    ], $extra);
}

function postFittingOrder(array $payload)
{
    return test()->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', $payload);
}

it('stores vehicle details, wheels and notes on the order', function () {
    $response = postFittingOrder(fittingOrderRequest([
        'vehicle' => ['rego' => 'ABC123', 'state' => 'VIC', 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Silver', 'year' => 2020, 'wheels' => ['FL', 'FR', 'RL', 'RR']],
        'notes' => 'Gate code 1234',
    ]));

    $response->assertCreated();

    expect(Order::query()->findOrFail($response->json('data.id'))->fitting_details)->toEqual([
        'rego' => 'ABC123', 'rego_state' => 'VIC', 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Silver',
        'year' => 2020, 'wheels' => ['FL', 'FR', 'RL', 'RR'], 'notes' => 'Gate code 1234',
    ]);
});

it('leaves fitting_details null when nothing extra is sent', function () {
    $response = postFittingOrder(fittingOrderRequest())->assertCreated();

    expect(Order::query()->findOrFail($response->json('data.id'))->fitting_details)->toBeNull();
});

it('subscribes the customer email when newsletter_opt_in is true', function () {
    postFittingOrder(fittingOrderRequest(['newsletter_opt_in' => true]))->assertCreated();

    $subscriber = NewsletterSubscriber::query()->sole();
    expect($subscriber->email)->toBe('jane@example.com')->and($subscriber->source)->toBe('checkout');
});

it('does not subscribe without the opt-in', function () {
    postFittingOrder(fittingOrderRequest(['newsletter_opt_in' => false]))->assertCreated();

    expect(NewsletterSubscriber::query()->count())->toBe(0);
});

it('rejects invalid wheel positions and oversized notes', function () {
    postFittingOrder(fittingOrderRequest(['vehicle' => ['wheels' => ['FL', 'XX']]]))->assertUnprocessable()->assertJsonValidationErrors('vehicle.wheels.1');
    postFittingOrder(fittingOrderRequest(['vehicle' => ['wheels' => ['FL', 'FL']]]))->assertUnprocessable();
    postFittingOrder(fittingOrderRequest(['notes' => str_repeat('a', 2001)]))->assertUnprocessable()->assertJsonValidationErrors('notes');
});
