<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\PriceGuaranteeClaim;
use App\Models\TyreVariant;

/**
 * `POST /api/v1/price-guarantee-claims`, `GET /api/v1/price-guarantee-claims`
 * — see docs/architecture/02-api-contract.md's "Promotions &
 * Price-Guarantee endpoints" section. `auth:customer`-only, no guest path,
 * no `Idempotency-Key` required.
 */
function customerBearer(Customer $customer): string
{
    return 'Bearer '.$customer->createToken('storefront')->plainTextToken;
}

it('creates a pending pre-purchase claim for the authenticated customer', function () {
    $customer = Customer::factory()->activated()->create();
    $variant = TyreVariant::factory()->create();

    $response = $this->withHeader('Authorization', customerBearer($customer))
        ->postJson('/api/v1/price-guarantee-claims', [
            'competitor_url' => 'https://example.com/tyre-deal',
            'competitor_price' => 15900,
            'tyre_variant_id' => $variant->id,
        ]);

    $response->assertCreated();
    expect($response->json('data.status'))->toBe('pending');
    expect($response->json('data.order_id'))->toBeNull();

    $claim = PriceGuaranteeClaim::query()->findOrFail($response->json('data.id'));
    expect($claim->customer_id)->toBe($customer->id);
});

it('creates a post-purchase claim when order_id belongs to the authenticated customer', function () {
    $customer = Customer::factory()->activated()->create();
    $variant = TyreVariant::factory()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    $response = $this->withHeader('Authorization', customerBearer($customer))
        ->postJson('/api/v1/price-guarantee-claims', [
            'competitor_url' => 'https://example.com/tyre-deal',
            'competitor_price' => 15900,
            'tyre_variant_id' => $variant->id,
            'order_id' => $order->id,
        ]);

    $response->assertCreated();
    expect($response->json('data.order_id'))->toBe($order->id);
});

it('403s when order_id does not belong to the authenticated customer', function () {
    $customer = Customer::factory()->activated()->create();
    $otherCustomer = Customer::factory()->activated()->create();
    $variant = TyreVariant::factory()->create();
    $order = Order::factory()->create(['customer_id' => $otherCustomer->id]);

    $response = $this->withHeader('Authorization', customerBearer($customer))
        ->postJson('/api/v1/price-guarantee-claims', [
            'competitor_url' => 'https://example.com/tyre-deal',
            'competitor_price' => 15900,
            'tyre_variant_id' => $variant->id,
            'order_id' => $order->id,
        ]);

    $response->assertStatus(403);
});

it('401s for an unauthenticated (guest) request', function () {
    $variant = TyreVariant::factory()->create();

    $response = $this->postJson('/api/v1/price-guarantee-claims', [
        'competitor_url' => 'https://example.com/tyre-deal',
        'competitor_price' => 15900,
        'tyre_variant_id' => $variant->id,
    ]);

    $response->assertStatus(401);
});

it('422s on a malformed body', function () {
    $customer = Customer::factory()->activated()->create();

    $response = $this->withHeader('Authorization', customerBearer($customer))
        ->postJson('/api/v1/price-guarantee-claims', [
            'competitor_url' => 'not-a-url',
            'competitor_price' => -1,
            'tyre_variant_id' => 999999,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['competitor_url', 'competitor_price', 'tyre_variant_id']);
});

it('does not require an Idempotency-Key header', function () {
    $customer = Customer::factory()->activated()->create();
    $variant = TyreVariant::factory()->create();

    $response = $this->withHeader('Authorization', customerBearer($customer))
        ->postJson('/api/v1/price-guarantee-claims', [
            'competitor_url' => 'https://example.com/tyre-deal',
            'competitor_price' => 15900,
            'tyre_variant_id' => $variant->id,
        ]);

    $response->assertCreated();
});

it('lists only the authenticated customer\'s own claims, paginated', function () {
    $customer = Customer::factory()->activated()->create();
    $otherCustomer = Customer::factory()->activated()->create();

    PriceGuaranteeClaim::factory()->count(2)->create(['customer_id' => $customer->id]);
    PriceGuaranteeClaim::factory()->create(['customer_id' => $otherCustomer->id]);

    $response = $this->withHeader('Authorization', customerBearer($customer))
        ->getJson('/api/v1/price-guarantee-claims');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('401s the list endpoint for a guest', function () {
    $response = $this->getJson('/api/v1/price-guarantee-claims');

    $response->assertStatus(401);
});
