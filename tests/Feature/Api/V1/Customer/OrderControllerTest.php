<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Order;

/**
 * `GET /api/v1/customer/orders` — the authenticated customer's order/booking
 * history. See docs/architecture (Phase 7 readiness pass). No separate
 * `/customer/bookings` endpoint — the booking summary is nested per order.
 */
function orderCustomerBearer(Customer $customer): string
{
    return 'Bearer '.$customer->createToken('storefront')->plainTextToken;
}

it('401s for a guest', function () {
    $this->getJson('/api/v1/customer/orders')->assertStatus(401);
});

it('lists only the authenticated customer\'s own orders, newest placed_at first', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();

    $older = Order::factory()->confirmed()->create(['customer_id' => $customer->id, 'placed_at' => now()->subDays(2)]);
    $newer = Order::factory()->confirmed()->create(['customer_id' => $customer->id, 'placed_at' => now()->subDay()]);
    Order::factory()->confirmed()->create(['customer_id' => $other->id]);

    $response = $this->withHeader('Authorization', orderCustomerBearer($customer))->getJson('/api/v1/customer/orders');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.id'))->toBe($newer->id);
    expect($response->json('data.1.id'))->toBe($older->id);
});

/**
 * Coverage-gap fill (phase test-scope item 1): a guest checkout order
 * (`customer_id === null`) must never leak into an authenticated customer's
 * own order history, even though both this endpoint's query and the guest
 * order coexist in the same `orders` table. The `where('customer_id',
 * $customer->id)` scope in `CustomerOrderController::index()` naturally
 * excludes a `null` row, but this was never asserted directly — every
 * existing test here only used a second *authenticated* customer's order as
 * the negative case.
 */
it('never leaks a guest order (customer_id null) into an authenticated customer\'s order list', function () {
    $customer = Customer::factory()->create();
    $own = Order::factory()->confirmed()->create(['customer_id' => $customer->id]);
    Order::factory()->confirmed()->create(['customer_id' => null]);

    $response = $this->withHeader('Authorization', orderCustomerBearer($customer))->getJson('/api/v1/customer/orders');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($own->id);
});

it('shapes each order summary with its linked booking\'s appointment', function () {
    $customer = Customer::factory()->create();
    $order = Order::factory()->confirmed()->create(['customer_id' => $customer->id]);
    $order->booking->forceFill([
        'scheduled_date' => '2026-10-01',
        'slot_start' => '09:00:00',
        'slot_end' => '09:45:00',
    ])->save();

    $response = $this->withHeader('Authorization', orderCustomerBearer($customer))->getJson('/api/v1/customer/orders');

    $response->assertOk();
    $data = $response->json('data.0');
    expect($data)->toHaveKeys(['id', 'order_number', 'status', 'payment_status', 'grand_total', 'currency', 'placed_at', 'booking']);
    expect($data['booking'])->toBe([
        'scheduled_date' => '2026-10-01',
        'slot_start' => '09:00',
        'slot_end' => '09:45',
    ]);
});

it('paginates using the standard envelope', function () {
    $customer = Customer::factory()->create();
    Order::factory()->count(3)->confirmed()->create(['customer_id' => $customer->id]);

    $response = $this->withHeader('Authorization', orderCustomerBearer($customer))->getJson('/api/v1/customer/orders');

    $response->assertOk();
    expect($response->json())->toHaveKeys(['data', 'links', 'meta']);
});
