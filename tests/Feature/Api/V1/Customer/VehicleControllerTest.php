<?php

use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Vehicle;

/**
 * `/api/v1/customer/vehicles` — see docs/architecture (Phase 7 readiness
 * pass). `auth:customer`-only, no guest path.
 */
function vehicleCustomerBearer(Customer $customer): string
{
    return 'Bearer '.$customer->createToken('storefront')->plainTextToken;
}

it('401s every route for a guest', function () {
    $this->getJson('/api/v1/customer/vehicles')->assertStatus(401);
    $this->postJson('/api/v1/customer/vehicles', [])->assertStatus(401);
});

it('lists only the authenticated customer\'s own saved vehicles', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    CustomerVehicle::factory()->count(2)->create(['customer_id' => $customer->id]);
    CustomerVehicle::factory()->create(['customer_id' => $other->id]);

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))->getJson('/api/v1/customer/vehicles');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('includes a nested vehicle summary only when vehicle_id is set', function () {
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->create(['make' => 'Toyota', 'model' => 'Corolla']);
    CustomerVehicle::factory()->create(['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'saved_fitment' => ['all' => ['width' => 215, 'profile' => 55, 'rim_diameter' => 17]]]);
    CustomerVehicle::factory()->create(['customer_id' => $customer->id, 'vehicle_id' => null, 'saved_fitment' => ['all' => ['width' => 215, 'profile' => 55, 'rim_diameter' => 17]]]);

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))->getJson('/api/v1/customer/vehicles');

    $response->assertOk();
    $withVehicle = collect($response->json('data'))->firstWhere('vehicle_id', $vehicle->id);
    $withoutVehicle = collect($response->json('data'))->firstWhere('vehicle_id', null);
    expect($withVehicle['vehicle']['make'])->toBe('Toyota');
    expect($withoutVehicle['vehicle'])->toBeNull();
});

it('creates a saved vehicle with a customer-typed fitment shape', function () {
    $customer = Customer::factory()->create();

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))->postJson('/api/v1/customer/vehicles', [
        'label' => 'My Corolla',
        'rego' => '1ABC234',
        'state' => 'VIC',
        'saved_fitment' => ['all' => ['width' => 215, 'profile' => 55, 'rim_diameter' => 17]],
    ]);

    $response->assertCreated();
    expect($response->json('data.label'))->toBe('My Corolla');
    $vehicle = CustomerVehicle::query()->findOrFail($response->json('data.id'));
    expect($vehicle->customer_id)->toBe($customer->id);
});

it('creates a saved vehicle with a front+rear fitment shape', function () {
    $customer = Customer::factory()->create();

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))->postJson('/api/v1/customer/vehicles', [
        'saved_fitment' => [
            'front' => ['width' => 225, 'profile' => 45, 'rim_diameter' => 18],
            'rear' => ['width' => 255, 'profile' => 40, 'rim_diameter' => 18],
        ],
    ]);

    $response->assertCreated();
});

it('creates a saved vehicle with an exact pass-through fitment shape including confidence', function () {
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->create();

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))->postJson('/api/v1/customer/vehicles', [
        'vehicle_id' => $vehicle->id,
        'saved_fitment' => [
            'all' => ['width' => 215, 'profile' => 55, 'rim_diameter' => 17, 'load_index' => '91', 'speed_rating' => 'V', 'confidence' => 'confirmed'],
        ],
    ]);

    $response->assertCreated();
});

it('422s when saved_fitment is missing', function () {
    $customer = Customer::factory()->create();

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))->postJson('/api/v1/customer/vehicles', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['saved_fitment']);
});

it('422s on a malformed saved_fitment shape (missing required field)', function () {
    $customer = Customer::factory()->create();

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))->postJson('/api/v1/customer/vehicles', [
        'saved_fitment' => ['all' => ['width' => 215, 'profile' => 55]],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['saved_fitment']);
});

it('422s on saved_fitment keyed by just "front" without "rear"', function () {
    $customer = Customer::factory()->create();

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))->postJson('/api/v1/customer/vehicles', [
        'saved_fitment' => ['front' => ['width' => 215, 'profile' => 55, 'rim_diameter' => 17]],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['saved_fitment']);
});

it('partially updates a saved vehicle it owns', function () {
    $customer = Customer::factory()->create();
    $vehicle = CustomerVehicle::factory()->create(['customer_id' => $customer->id, 'label' => 'Old label']);

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))
        ->patchJson("/api/v1/customer/vehicles/{$vehicle->id}", ['label' => 'New label']);

    $response->assertOk();
    expect($response->json('data.label'))->toBe('New label');
});

it('404s updating another customer\'s saved vehicle', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $vehicle = CustomerVehicle::factory()->create(['customer_id' => $other->id]);

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))
        ->patchJson("/api/v1/customer/vehicles/{$vehicle->id}", ['label' => 'New label']);

    $response->assertStatus(404);
});

it('hard deletes a saved vehicle it owns', function () {
    $customer = Customer::factory()->create();
    $vehicle = CustomerVehicle::factory()->create(['customer_id' => $customer->id]);

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))
        ->deleteJson("/api/v1/customer/vehicles/{$vehicle->id}");

    $response->assertNoContent();
    expect(CustomerVehicle::query()->find($vehicle->id))->toBeNull();
});

it('404s deleting another customer\'s saved vehicle', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $vehicle = CustomerVehicle::factory()->create(['customer_id' => $other->id]);

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))
        ->deleteJson("/api/v1/customer/vehicles/{$vehicle->id}");

    $response->assertStatus(404);
    expect(CustomerVehicle::query()->find($vehicle->id))->not->toBeNull();
});

it('returns the same 404 body shape for a nonexistent id as for a mismatched-owner id (no enumeration signal)', function () {
    // Reproduces the production posture (see security review, Phase 7 item
    // 2): a message-less abort()'s HttpException message IS suppressed by
    // APP_DEBUG=false, so both cases only look identical once debug output
    // is off — checked with it explicitly disabled rather than relying on
    // whatever this environment's default happens to be.
    config(['app.debug' => false]);

    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $othersVehicle = CustomerVehicle::factory()->create(['customer_id' => $other->id]);
    $nonexistentId = $othersVehicle->id + 999999;

    $mismatchedOwnerResponse = $this->withHeader('Authorization', vehicleCustomerBearer($customer))
        ->postJson("/api/v1/customer/vehicles/{$othersVehicle->id}/set-default");
    $nonexistentResponse = $this->withHeader('Authorization', vehicleCustomerBearer($customer))
        ->postJson("/api/v1/customer/vehicles/{$nonexistentId}/set-default");

    $mismatchedOwnerResponse->assertStatus(404);
    $nonexistentResponse->assertStatus(404);
    // Both must now be Eloquent's own "No query results for model [...] $id"
    // message — previously the mismatched-owner case hit a message-less
    // abort() instead, letting a customer tell the two cases apart even
    // though both returned 404.
    // Identical, generic body in both cases (no model/id leak, no enumeration signal).
    expect($mismatchedOwnerResponse->json())->toBe($nonexistentResponse->json());
    expect($nonexistentResponse->json('code'))->toBe('not_found');
});

it('sets a vehicle as default, unsetting any other default', function () {
    $customer = Customer::factory()->create();
    $current = CustomerVehicle::factory()->create(['customer_id' => $customer->id, 'is_default' => true]);
    $target = CustomerVehicle::factory()->create(['customer_id' => $customer->id, 'is_default' => false]);

    $response = $this->withHeader('Authorization', vehicleCustomerBearer($customer))
        ->postJson("/api/v1/customer/vehicles/{$target->id}/set-default");

    $response->assertOk();
    expect($response->json('data.is_default'))->toBeTrue();
    expect($current->refresh()->is_default)->toBeFalse();
});
