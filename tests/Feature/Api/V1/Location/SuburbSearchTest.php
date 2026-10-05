<?php

use App\Models\ServiceZone;
use App\Models\State;
use App\Models\Suburb;

/**
 * `GET /api/v1/suburbs/search` (see docs/redesign/api-contract-phase7.md section 5).
 */
it('finds suburbs by name prefix and flags serviceability', function () {
    $state = State::factory()->create(['code' => 'VIC']);
    $zone = ServiceZone::factory()->create(['state_id' => $state->id]);
    $served = Suburb::factory()->create(['state_id' => $state->id, 'name' => 'Richmond', 'postcode' => '3121', 'lat' => $zone->origin_lat, 'lng' => $zone->origin_lng]);
    $far = Suburb::factory()->create(['state_id' => $state->id, 'name' => 'Richmond Far', 'postcode' => '3999', 'lat' => -10.0, 'lng' => 100.0]);

    $data = $this->getJson('/api/v1/suburbs/search?q=rich')->assertOk()->json('data');

    expect($data)->toHaveCount(2)
        ->and($data[0])->toBe([
            'id' => $served->id, 'name' => 'Richmond', 'state' => 'VIC', 'postcode' => '3121',
            'label' => 'Richmond VIC 3121', 'serviceable' => true, 'service_zone_id' => $zone->id,
        ])
        ->and($data[1]['id'])->toBe($far->id)
        ->and($data[1]['serviceable'])->toBeFalse()
        ->and($data[1]['service_zone_id'])->toBeNull();
});

it('finds suburbs by postcode prefix and honours the limit', function () {
    $state = State::factory()->create();
    Suburb::factory()->count(3)->sequence(
        ['postcode' => '3121'], ['postcode' => '3122'], ['postcode' => '2000'],
    )->create(['state_id' => $state->id]);

    $this->getJson('/api/v1/suburbs/search?q=312')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/suburbs/search?q=312&limit=1')->assertOk()->assertJsonCount(1, 'data');
});

it('requires at least two characters and treats wildcards literally', function () {
    $this->getJson('/api/v1/suburbs/search?q=r')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/v1/suburbs/search')->assertUnprocessable();

    Suburb::factory()->create(['name' => 'Richmond']);
    $this->getJson('/api/v1/suburbs/search?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
});
