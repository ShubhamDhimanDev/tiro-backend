<?php

use App\Models\Address;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLineItem;

/**
 * `GET /api/v1/orders/{order}` — see docs/architecture/02-api-contract.md's
 * "Cart, Checkout & Payment endpoints" section. Mirrors
 * `GET /api/v1/bookings/{booking}`'s auth pattern exactly.
 */
it('returns order state + line_items + booking summary for the guest owner via X-Order-Token', function () {
    $booking = Booking::factory()->confirmed()->create();
    $address = Address::factory()->create();
    $order = Order::factory()->create([
        'booking_id' => $booking->id,
        'address_id' => $address->id,
        'guest_token_hash' => Order::hashGuestToken('order-token-123'),
    ]);
    OrderLineItem::factory()->create(['order_id' => $order->id]);

    $response = $this->withHeader('X-Order-Token', 'order-token-123')->getJson("/api/v1/orders/{$order->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($order->id);
    expect($response->json('data.line_items'))->toHaveCount(1);
    expect($response->json('data.booking.scheduled_date'))->toBe($booking->scheduled_date->toDateString());
    // Creation-response-only fields never appear on GET.
    expect($response->json('data'))->not->toHaveKey('order_token');
    expect($response->json('data'))->not->toHaveKey('order_token_issued');
    expect($response->json('data'))->not->toHaveKey('payment');
});

it('returns order state for the authenticated owner', function () {
    $customer = Customer::factory()->activated()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->getJson("/api/v1/orders/{$order->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($order->id);
});

it('403s for an authenticated customer who does not own the order', function () {
    $customer = Customer::factory()->activated()->create();
    $order = Order::factory()->create(['customer_id' => Customer::factory()->create()->id]);

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->getJson("/api/v1/orders/{$order->id}");

    $response->assertStatus(403);
});

it('403s for a guest with no order token or a wrong one', function () {
    $order = Order::factory()->create(['guest_token_hash' => Order::hashGuestToken('order-token-123')]);

    $this->getJson("/api/v1/orders/{$order->id}")->assertStatus(403);
    $this->withHeader('X-Order-Token', 'wrong-token')->getJson("/api/v1/orders/{$order->id}")->assertStatus(403);
});

it('404s before the auth check for a nonexistent order', function () {
    $response = $this->getJson('/api/v1/orders/999999');

    $response->assertStatus(404);
});
