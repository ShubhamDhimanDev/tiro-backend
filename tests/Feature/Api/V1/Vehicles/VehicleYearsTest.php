<?php

use App\Enums\Status;
use App\Models\Vehicle;

/**
 * `GET /api/v1/vehicles/years` — see docs/architecture/02-api-contract.md.
 */
it('returns candidate vehicles for a make/model pair, sorted year_from descending', function () {
    $older = Vehicle::factory()->create([
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent',
        'body_type' => 'sedan', 'year_from' => 2013, 'year_to' => 2018,
    ]);
    $newerSedan = Vehicle::factory()->create([
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport',
        'body_type' => 'sedan', 'year_from' => 2019, 'year_to' => 2023,
    ]);
    $newerHatch = Vehicle::factory()->create([
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport',
        'body_type' => 'hatch', 'year_from' => 2019, 'year_to' => 2023,
    ]);

    $response = $this->getJson('/api/v1/vehicles/years?make=Toyota&model=Corolla');

    $response->assertOk()->assertJsonStructure([
        'data' => ['*' => ['id', 'year_from', 'year_to', 'series', 'body_type']],
    ]);

    $ids = collect($response->json('data'))->pluck('id')->all();

    // newerSedan and newerHatch share year_from=2019 — their relative order
    // between each other is untested (the contract only guarantees
    // year_from descending), but both must sort ahead of $older.
    expect($ids)->toHaveCount(3)
        ->and(array_slice($ids, 0, 2))->toEqualCanonicalizing([$newerSedan->id, $newerHatch->id])
        ->and($ids[2])->toBe($older->id);
});

it('requires both make and model, 422 when either is missing', function () {
    $this->getJson('/api/v1/vehicles/years?make=Toyota')
        ->assertStatus(422)->assertJsonValidationErrors('model');

    $this->getJson('/api/v1/vehicles/years?model=Corolla')
        ->assertStatus(422)->assertJsonValidationErrors('make');
});

it('returns 200 with an empty array for a make/model pair with no active vehicles', function () {
    Vehicle::factory()->create(['make' => 'Toyota', 'model' => 'Corolla', 'status' => Status::Inactive]);

    $response = $this->getJson('/api/v1/vehicles/years?make=Toyota&model=Corolla');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});
