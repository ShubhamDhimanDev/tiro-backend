<?php

use App\Models\Address;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Suburb;

/**
 * `/api/v1/customer/addresses` — see docs/architecture (Phase 7 readiness
 * pass). `auth:customer`-only, no guest path.
 */
function addressCustomerBearer(Customer $customer): string
{
    return 'Bearer '.$customer->createToken('storefront')->plainTextToken;
}

it('401s every route for a guest', function () {
    $this->getJson('/api/v1/customer/addresses')->assertStatus(401);
    $this->postJson('/api/v1/customer/addresses', [])->assertStatus(401);
});

it('lists only the authenticated customer\'s own saved addresses, including historical checkout addresses', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    Address::factory()->count(2)->create(['customer_id' => $customer->id]);
    Address::factory()->create(['customer_id' => $other->id]);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))->getJson('/api/v1/customer/addresses');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('shapes the suburb as a nested object', function () {
    $customer = Customer::factory()->create();
    $suburb = Suburb::factory()->create(['name' => 'Richmond']);
    Address::factory()->create(['customer_id' => $customer->id, 'suburb_id' => $suburb->id]);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))->getJson('/api/v1/customer/addresses');

    $response->assertOk();
    expect($response->json('data.0.suburb.name'))->toBe('Richmond');
    expect($response->json('data.0.suburb'))->toHaveKeys(['id', 'name', 'state']);
});

it('creates a saved address of type fitting, deriving postcode from the suburb', function () {
    $customer = Customer::factory()->create();
    $suburb = Suburb::factory()->create(['postcode' => '3121']);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))->postJson('/api/v1/customer/addresses', [
        'label' => 'Home',
        'suburb_id' => $suburb->id,
        'line1' => '1 Example St',
        'lat' => -37.8,
        'lng' => 144.9,
    ]);

    $response->assertCreated();
    expect($response->json('data.postcode'))->toBe('3121');
    $address = Address::query()->findOrFail($response->json('data.id'));
    expect($address->customer_id)->toBe($customer->id);
    expect($address->type->value)->toBe('fitting');
});

it('422s when required address fields are missing', function () {
    $customer = Customer::factory()->create();

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))->postJson('/api/v1/customer/addresses', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['suburb_id', 'line1', 'lat', 'lng']);
});

it('partially updates a saved address it owns', function () {
    $customer = Customer::factory()->create();
    $address = Address::factory()->create(['customer_id' => $customer->id, 'label' => 'Old']);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))
        ->patchJson("/api/v1/customer/addresses/{$address->id}", ['label' => 'New']);

    $response->assertOk();
    expect($response->json('data.label'))->toBe('New');
});

it('404s updating another customer\'s saved address', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $address = Address::factory()->create(['customer_id' => $other->id]);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))
        ->patchJson("/api/v1/customer/addresses/{$address->id}", ['label' => 'New']);

    $response->assertStatus(404);
});

it('hard deletes a saved address with no order/booking referencing it', function () {
    $customer = Customer::factory()->create();
    $address = Address::factory()->create(['customer_id' => $customer->id]);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))
        ->deleteJson("/api/v1/customer/addresses/{$address->id}");

    $response->assertNoContent();
    expect(Address::query()->find($address->id))->toBeNull();
});

it('409s deleting an address referenced by an order, with a helpful message', function () {
    $customer = Customer::factory()->create();
    $address = Address::factory()->create(['customer_id' => $customer->id]);
    Order::factory()->create(['customer_id' => $customer->id, 'address_id' => $address->id]);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))
        ->deleteJson("/api/v1/customer/addresses/{$address->id}");

    $response->assertStatus(409);
    expect($response->json('message'))->toContain("can't be removed");
    expect(Address::query()->find($address->id))->not->toBeNull();
});

it('409s deleting an address referenced by a booking', function () {
    $customer = Customer::factory()->create();
    $address = Address::factory()->create(['customer_id' => $customer->id]);
    Booking::factory()->create(['customer_id' => $customer->id, 'address_id' => $address->id]);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))
        ->deleteJson("/api/v1/customer/addresses/{$address->id}");

    $response->assertStatus(409);
});

it('404s deleting another customer\'s saved address', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $address = Address::factory()->create(['customer_id' => $other->id]);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))
        ->deleteJson("/api/v1/customer/addresses/{$address->id}");

    $response->assertStatus(404);
});

it('sets an address as default, unsetting any other default', function () {
    $customer = Customer::factory()->create();
    $current = Address::factory()->create(['customer_id' => $customer->id, 'is_default' => true]);
    $target = Address::factory()->create(['customer_id' => $customer->id, 'is_default' => false]);

    $response = $this->withHeader('Authorization', addressCustomerBearer($customer))
        ->postJson("/api/v1/customer/addresses/{$target->id}/set-default");

    $response->assertOk();
    expect($response->json('data.is_default'))->toBeTrue();
    expect($current->refresh()->is_default)->toBeFalse();
});

it('returns the same 404 body shape for a nonexistent id as for a mismatched-owner id (no enumeration signal)', function () {
    // See VehicleControllerTest's identical case for why app.debug is
    // explicitly forced off — security review, Phase 7 item 2.
    config(['app.debug' => false]);

    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $othersAddress = Address::factory()->create(['customer_id' => $other->id]);
    $nonexistentId = $othersAddress->id + 999999;

    $mismatchedOwnerResponse = $this->withHeader('Authorization', addressCustomerBearer($customer))
        ->postJson("/api/v1/customer/addresses/{$othersAddress->id}/set-default");
    $nonexistentResponse = $this->withHeader('Authorization', addressCustomerBearer($customer))
        ->postJson("/api/v1/customer/addresses/{$nonexistentId}/set-default");

    $mismatchedOwnerResponse->assertStatus(404);
    $nonexistentResponse->assertStatus(404);
    // Identical, generic body in both cases (no model/id leak, no enumeration signal).
    expect($mismatchedOwnerResponse->json())->toBe($nonexistentResponse->json());
    expect($nonexistentResponse->json('code'))->toBe('not_found');
});
