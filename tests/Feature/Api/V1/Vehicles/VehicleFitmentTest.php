<?php

use App\Enums\Status;
use App\Enums\VehicleFitmentConfidence;
use App\Enums\VehicleFitmentPosition;
use App\Models\Vehicle;
use App\Models\VehicleFitment;

/**
 * `GET /api/v1/vehicles/{vehicle}/fitment` — see
 * docs/architecture/02-api-contract.md.
 */
it('returns the non-staggered fitment nested under an "all" key', function () {
    $vehicle = Vehicle::factory()->create([
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport',
        'body_type' => 'sedan', 'year_from' => 2019, 'year_to' => 2023,
    ]);
    VehicleFitment::factory()->for($vehicle)->create([
        'position' => VehicleFitmentPosition::All,
        'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '91', 'speed_rating' => 'V',
        'is_staggered' => false,
        'confidence' => VehicleFitmentConfidence::Confirmed,
    ]);

    $response = $this->getJson("/api/v1/vehicles/{$vehicle->id}/fitment");

    $response->assertOk()->assertExactJson(['data' => [
        'vehicle' => [
            'id' => $vehicle->id, 'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport',
            'year_from' => 2019, 'year_to' => 2023, 'body_type' => 'sedan',
        ],
        'is_staggered' => false,
        'fitments' => [
            'all' => [
                'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
                'load_index' => '91', 'speed_rating' => 'V', 'confidence' => 'confirmed',
            ],
        ],
    ]]);
});

it('returns front/rear keys for a staggered vehicle', function () {
    $vehicle = Vehicle::factory()->create();
    VehicleFitment::factory()->for($vehicle)->front()->create(['width' => 205, 'profile' => 55, 'rim_diameter' => 16]);
    VehicleFitment::factory()->for($vehicle)->rear()->create(['width' => 245, 'profile' => 40, 'rim_diameter' => 18]);

    $response = $this->getJson("/api/v1/vehicles/{$vehicle->id}/fitment");

    $response->assertOk();
    expect($response->json('data.is_staggered'))->toBeTrue()
        ->and($response->json('data.fitments.front.width'))->toBe(205)
        ->and($response->json('data.fitments.rear.width'))->toBe(245)
        ->and($response->json('data.fitments'))->not->toHaveKey('all');
});

it('returns an empty fitments object, not array, for a vehicle with no fitment rows configured', function () {
    $vehicle = Vehicle::factory()->create();

    $response = $this->getJson("/api/v1/vehicles/{$vehicle->id}/fitment");

    $response->assertOk();
    expect($response->json('data.fitments'))->toBe([]);
    expect($response->getContent())->toContain('"fitments":{}');
});

it('excludes inactive fitment rows', function () {
    $vehicle = Vehicle::factory()->create();
    VehicleFitment::factory()->for($vehicle)->create([
        'position' => VehicleFitmentPosition::All, 'status' => Status::Inactive,
    ]);

    $response = $this->getJson("/api/v1/vehicles/{$vehicle->id}/fitment");

    $response->assertOk();
    expect($response->json('data.fitments'))->toBe([]);
});

it('returns 404 for a vehicle id that does not exist', function () {
    $response = $this->getJson('/api/v1/vehicles/999999/fitment');

    $response->assertNotFound();
});

it('returns 404 for an inactive vehicle', function () {
    $vehicle = Vehicle::factory()->create(['status' => Status::Inactive]);

    $response = $this->getJson("/api/v1/vehicles/{$vehicle->id}/fitment");

    $response->assertNotFound();
});
