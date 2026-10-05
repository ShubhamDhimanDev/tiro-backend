<?php

use App\Models\State;
use App\Models\Suburb;

/**
 * `GET /api/v1/suburbs` — see docs/architecture/02-api-contract.md's "Zone
 * resolution / overlap rule" section and `SuburbController`'s own docblock.
 */
it('resolves a single unambiguous match by postcode and name, case-insensitively', function () {
    $vic = State::factory()->create(['code' => 'VIC']);
    $suburb = Suburb::factory()->create([
        'name' => 'Richmond', 'state_id' => $vic->id, 'postcode' => '3121',
    ]);

    $response = $this->getJson('/api/v1/suburbs?postcode=3121&name=rIcHmOnD');

    $response->assertOk()->assertExactJson([
        'data' => [
            ['id' => $suburb->id, 'name' => 'Richmond', 'state' => 'VIC', 'postcode' => '3121'],
        ],
    ]);
});

it('returns 200 with an empty array when postcode and name match nothing, not a 404', function () {
    $response = $this->getJson('/api/v1/suburbs?postcode=9999&name=Nowhere');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('returns every candidate explicitly when postcode and name are ambiguous across states', function () {
    // The schema's unique constraint is (name, state_id, postcode) — the
    // same suburb name/postcode pair can legitimately exist in two states,
    // a genuine ambiguity this endpoint must surface, not silently collapse.
    $vic = State::factory()->create(['code' => 'VIC']);
    $nsw = State::factory()->create(['code' => 'NSW']);

    $vicMatch = Suburb::factory()->create([
        'name' => 'Richmond', 'state_id' => $vic->id, 'postcode' => '3121',
    ]);
    $nswMatch = Suburb::factory()->create([
        'name' => 'Richmond', 'state_id' => $nsw->id, 'postcode' => '3121',
    ]);

    $response = $this->getJson('/api/v1/suburbs?postcode=3121&name=Richmond');

    $response->assertOk();
    $states = collect($response->json('data'))->pluck('state')->all();

    expect($response->json('data'))->toHaveCount(2)
        ->and($states)->toEqualCanonicalizing(['VIC', 'NSW'])
        ->and(collect($response->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing([$vicMatch->id, $nswMatch->id]);
});

it('does not match a suburb with the same name in a different postcode', function () {
    $vic = State::factory()->create(['code' => 'VIC']);
    Suburb::factory()->create(['name' => 'Richmond', 'state_id' => $vic->id, 'postcode' => '3121']);

    $response = $this->getJson('/api/v1/suburbs?postcode=3000&name=Richmond');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('requires postcode, 422 when missing', function () {
    $response = $this->getJson('/api/v1/suburbs?name=Richmond');

    $response->assertStatus(422)->assertJsonValidationErrors('postcode');
});

it('requires name, 422 when missing', function () {
    $response = $this->getJson('/api/v1/suburbs?postcode=3121');

    $response->assertStatus(422)->assertJsonValidationErrors('name');
});

it('rejects a malformed postcode', function () {
    $response = $this->getJson('/api/v1/suburbs?postcode=abcde&name=Richmond');

    $response->assertStatus(422)->assertJsonValidationErrors('postcode');
});
