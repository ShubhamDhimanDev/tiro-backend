<?php

use App\Enums\Status;
use App\Models\ServiceZone;
use Database\Seeders\LocationSeeder;

/**
 * `POST /api/v1/serviceability` — exercises the exact "Zone resolution /
 * overlap rule" from docs/architecture/02-api-contract.md against the real
 * `LocationSeeder` fixtures (not just isolated factory scenarios), since
 * that's the only way to prove the priority tie-break genuinely fires: two
 * of the seeded radius zones (Melbourne Metro, Melbourne CBD Express) share
 * an origin specifically so every suburb inside the smaller zone's radius
 * is tied on distance.
 */
beforeEach(function () {
    $this->seed(LocationSeeder::class);
});

it('resolves an exact suburb_list match by postcode', function () {
    $response = $this->postJson('/api/v1/serviceability', ['postcode' => '2042']);

    $zone = ServiceZone::query()->where('name', 'Sydney Inner West')->firstOrFail();

    $response->assertOk()->assertExactJson([
        'serviceable' => true,
        'service_zone_id' => $zone->id,
        'label' => 'Sydney Inner West',
        'suggested_areas' => [],
    ]);
});

it('resolves an exact suburb_list match by suburb name, case-insensitively', function () {
    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'mArrickville']);

    $response->assertOk()
        ->assertJsonPath('serviceable', true)
        ->assertJsonPath('label', 'Sydney Inner West');
});

it('reports not serviceable for a suburb outside every zone', function () {
    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'Bondi']);

    $response->assertOk()->assertExactJson([
        'serviceable' => false,
        'service_zone_id' => null,
        'label' => null,
        'suggested_areas' => [],
    ]);
});

it('resolves a radius zone with a single candidate to that zone', function () {
    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'St Kilda']);

    // 6.2km from the Melbourne CBD origin — inside Melbourne Metro's 25km
    // radius, but outside Melbourne CBD Express's 5km radius, so only one
    // zone matches and no tie-break is involved.
    $response->assertOk()
        ->assertJsonPath('serviceable', true)
        ->assertJsonPath('label', 'Melbourne Metro');
});

it('resolves a radius zone tie via the higher-priority zone', function () {
    // Melbourne CBD sits at distance 0 from both Melbourne Metro (priority
    // 10) and Melbourne CBD Express (priority 20) — a genuine tie that only
    // `ServiceZone.priority` breaks.
    $response = $this->postJson('/api/v1/serviceability', ['postcode' => '3000']);

    $response->assertOk()
        ->assertJsonPath('serviceable', true)
        ->assertJsonPath('label', 'Melbourne CBD Express');
});

it('resolves a radius zone tie for every suburb inside the smaller zone, not just the shared origin', function () {
    // Richmond (3.3km from the shared origin) is inside both Melbourne
    // Metro (25km) and Melbourne CBD Express (5km) radii — same tie as the
    // origin suburb itself, proving the tie-break isn't a distance-zero
    // special case.
    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'Richmond']);

    $response->assertOk()->assertJsonPath('label', 'Melbourne CBD Express');
});

it('falls back to the remaining radius zone once the higher-priority tied zone is inactive', function () {
    ServiceZone::query()->where('name', 'Melbourne CBD Express')->update(['status' => Status::Inactive]);

    $response = $this->postJson('/api/v1/serviceability', ['postcode' => '3000']);

    $response->assertOk()->assertJsonPath('label', 'Melbourne Metro');
});

it('resolves a suburb to its own single-candidate radius zone', function () {
    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'Geelong']);

    $response->assertOk()->assertJsonPath('label', 'Geelong & Surrounds');
});

it('reports not serviceable for a real suburb too far from every zone', function () {
    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'Ballarat']);

    $response->assertOk()->assertJsonPath('serviceable', false);
});

it('reports not serviceable for an unrecognised postcode without erroring', function () {
    $response = $this->postJson('/api/v1/serviceability', ['postcode' => '9999']);

    $response->assertOk()->assertJsonPath('serviceable', false);
});

it('rejects a request missing both postcode and suburb', function () {
    $response = $this->postJson('/api/v1/serviceability', []);

    $response->assertStatus(422)->assertJsonValidationErrors(['postcode', 'suburb']);
});

it('rejects a malformed postcode', function () {
    $response = $this->postJson('/api/v1/serviceability', ['postcode' => 'abcde']);

    $response->assertStatus(422)->assertJsonValidationErrors('postcode');
});
