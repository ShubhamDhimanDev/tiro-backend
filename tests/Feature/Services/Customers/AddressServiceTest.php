<?php

use App\Models\Address;
use App\Models\Customer;
use App\Services\Customers\AddressService;

/**
 * `Address.is_default` is write-layer-enforced (single default per
 * customer), not a DB constraint — see {@see AddressService}'s docblock.
 */
beforeEach(function () {
    $this->service = new AddressService;
});

it('sets the given address as default and unsets any other default for the same customer', function () {
    $customer = Customer::factory()->create();
    $current = Address::factory()->create(['customer_id' => $customer->id, 'is_default' => true]);
    $new = Address::factory()->create(['customer_id' => $customer->id, 'is_default' => false]);

    $result = $this->service->setDefault($new);

    expect($result->is_default)->toBeTrue();
    expect($current->refresh()->is_default)->toBeFalse();
    expect($new->refresh()->is_default)->toBeTrue();
});

it('does not affect another customer\'s default address', function () {
    $customerA = Customer::factory()->create();
    $customerB = Customer::factory()->create();
    $othersDefault = Address::factory()->create(['customer_id' => $customerB->id, 'is_default' => true]);
    $address = Address::factory()->create(['customer_id' => $customerA->id, 'is_default' => false]);

    $this->service->setDefault($address);

    expect($othersDefault->refresh()->is_default)->toBeTrue();
});
