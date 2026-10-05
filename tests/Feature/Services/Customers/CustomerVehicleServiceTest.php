<?php

use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Services\Customers\CustomerVehicleService;

/**
 * `CustomerVehicle.is_default` is write-layer-enforced (single default per
 * customer), not a DB constraint — see {@see CustomerVehicleService}'s
 * docblock.
 */
beforeEach(function () {
    $this->service = new CustomerVehicleService;
});

it('sets the given vehicle as default and unsets any other default for the same customer', function () {
    $customer = Customer::factory()->create();
    $current = CustomerVehicle::factory()->create(['customer_id' => $customer->id, 'is_default' => true]);
    $new = CustomerVehicle::factory()->create(['customer_id' => $customer->id, 'is_default' => false]);

    $result = $this->service->setDefault($new);

    expect($result->is_default)->toBeTrue();
    expect($current->refresh()->is_default)->toBeFalse();
    expect($new->refresh()->is_default)->toBeTrue();
});

it('does not affect another customer\'s default vehicle', function () {
    $customerA = Customer::factory()->create();
    $customerB = Customer::factory()->create();
    $othersDefault = CustomerVehicle::factory()->create(['customer_id' => $customerB->id, 'is_default' => true]);
    $vehicle = CustomerVehicle::factory()->create(['customer_id' => $customerA->id, 'is_default' => false]);

    $this->service->setDefault($vehicle);

    expect($othersDefault->refresh()->is_default)->toBeTrue();
});
