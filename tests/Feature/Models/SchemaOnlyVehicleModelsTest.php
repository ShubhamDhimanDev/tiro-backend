<?php

use App\Models\CustomerVehicle;
use App\Models\RegoLookupCache;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;

/**
 * `RegoLookupCache` and `CustomerVehicle` are schema-only this phase — no
 * controllers/endpoints/business logic exist yet (see
 * docs/architecture/01-data-model.md). These narrow tests only confirm the
 * migrations/models/relationships are wired correctly for future phases'
 * foreign keys to resolve against.
 */
it('persists a RegoLookupCache row and resolves its vehicle relationship', function () {
    $vehicle = Vehicle::factory()->create();

    $cache = RegoLookupCache::factory()->create([
        'rego' => 'ABC123', 'state' => 'VIC', 'resolved_vehicle_id' => $vehicle->id,
    ]);

    expect($cache->resolvedVehicle->is($vehicle))->toBeTrue()
        ->and($vehicle->regoLookupCaches->pluck('id'))->toContain($cache->id);
});

it('enforces uniqueness on (rego, state)', function () {
    RegoLookupCache::factory()->create(['rego' => 'ABC123', 'state' => 'VIC']);

    expect(fn () => RegoLookupCache::factory()->create(['rego' => 'ABC123', 'state' => 'VIC']))
        ->toThrow(QueryException::class);
});

it('persists a CustomerVehicle row and resolves its customer/vehicle relationships', function () {
    $vehicle = Vehicle::factory()->create();

    $saved = CustomerVehicle::factory()->create([
        'vehicle_id' => $vehicle->id,
        'saved_fitment' => ['width' => 205, 'profile' => 55, 'rim_diameter' => 16],
    ]);

    expect($saved->vehicle->is($vehicle))->toBeTrue()
        ->and($saved->customer)->not->toBeNull()
        ->and($vehicle->customerVehicles->pluck('id'))->toContain($saved->id);
});
