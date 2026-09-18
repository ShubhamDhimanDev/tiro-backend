<?php

use App\Enums\Status;
use App\Models\Vehicle;

/**
 * `GET /api/v1/vehicles/makes` — see docs/architecture/02-api-contract.md.
 */
it('returns distinct active makes, alphabetically sorted', function () {
    Vehicle::factory()->create(['make' => 'Toyota']);
    Vehicle::factory()->create(['make' => 'Mazda']);
    Vehicle::factory()->create(['make' => 'Toyota', 'model' => 'Something Else']);

    $response = $this->getJson('/api/v1/vehicles/makes');

    $response->assertOk();
    expect($response->json('data'))->toBe(['Mazda', 'Toyota']);
});

it('excludes makes that only have inactive vehicles', function () {
    Vehicle::factory()->create(['make' => 'Kia', 'status' => Status::Inactive]);
    Vehicle::factory()->create(['make' => 'Ford', 'status' => Status::Active]);

    $response = $this->getJson('/api/v1/vehicles/makes');

    $response->assertOk();
    expect($response->json('data'))->toBe(['Ford']);
});

it('returns an empty array, not an error, when no vehicles exist', function () {
    $response = $this->getJson('/api/v1/vehicles/makes');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});
