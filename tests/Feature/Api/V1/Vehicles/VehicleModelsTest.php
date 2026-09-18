<?php

use App\Enums\Status;
use App\Models\Vehicle;

/**
 * `GET /api/v1/vehicles/models` — see docs/architecture/02-api-contract.md.
 */
it('returns distinct active models for the given make, alphabetically sorted', function () {
    Vehicle::factory()->create(['make' => 'Toyota', 'model' => 'Corolla']);
    Vehicle::factory()->create(['make' => 'Toyota', 'model' => 'Camry']);
    Vehicle::factory()->create(['make' => 'Mazda', 'model' => 'CX-5']);

    $response = $this->getJson('/api/v1/vehicles/models?make=Toyota');

    $response->assertOk();
    expect($response->json('data'))->toBe(['Camry', 'Corolla']);
});

it('requires the make parameter, 422 when missing', function () {
    $response = $this->getJson('/api/v1/vehicles/models');

    $response->assertStatus(422)->assertJsonValidationErrors('make');
});

it('returns 200 with an empty array for an unrecognized make, not a 404', function () {
    Vehicle::factory()->create(['make' => 'Toyota']);

    $response = $this->getJson('/api/v1/vehicles/models?make=NotARealMake');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('excludes models that only belong to an inactive vehicle', function () {
    Vehicle::factory()->create(['make' => 'Toyota', 'model' => 'Retired Model', 'status' => Status::Inactive]);
    Vehicle::factory()->create(['make' => 'Toyota', 'model' => 'Corolla', 'status' => Status::Active]);

    $response = $this->getJson('/api/v1/vehicles/models?make=Toyota');

    $response->assertOk();
    expect($response->json('data'))->toBe(['Corolla']);
});
