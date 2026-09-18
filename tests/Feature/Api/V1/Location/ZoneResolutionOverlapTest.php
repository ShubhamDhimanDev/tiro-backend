<?php

use App\Models\ServiceZone;
use App\Models\State;
use App\Models\Suburb;

/**
 * `POST /api/v1/serviceability` — dedicated cross-cutting coverage for every
 * step of the "Zone resolution / overlap rule" from
 * docs/architecture/02-api-contract.md acting together in one fixture set,
 * distinct from `ServiceabilityTest.php`'s per-scenario checks against the
 * real `LocationSeeder` data. That seeder doesn't happen to contain a
 * suburb that is both an exact `suburb_list` member and inside an
 * overlapping `radius` zone, nor two `radius` zones with different origins
 * producing a genuine (non-tied) "nearest wins" comparison — both are
 * built here with factories instead, deliberately separated into two
 * geographic regions (each zone/suburb group placed far enough apart, and
 * radii kept small enough, that no fixture accidentally participates in a
 * step it isn't meant to exercise).
 */
beforeEach(function () {
    $state = State::factory()->create();

    // --- Region 1 (Melbourne-area coordinates): steps 1 and 2 ---
    ServiceZone::factory()->for($state)
        ->radius(-37.8000, 145.0000, 20)
        ->create(['name' => 'Region1 Radius A', 'priority' => 1]);

    ServiceZone::factory()->for($state)
        ->radius(-37.9000, 145.3000, 20)
        ->create(['name' => 'Region1 Radius B', 'priority' => 1]);

    $suburbListZone = ServiceZone::factory()->for($state)
        ->suburbList()
        ->create(['name' => 'Region1 Suburb List', 'priority' => 0]);

    // Inside Radius A's 20km radius (~0.6km from its origin) and outside
    // Radius B's — but also an explicit suburb_list member, so step 1 must
    // win despite a genuine overlapping radius candidate existing.
    $overlapSuburb = Suburb::factory()->for($state)->create([
        'name' => 'Overlap Suburb', 'lat' => -37.8050, 'lng' => 145.0050,
    ]);
    $suburbListZone->suburbs()->attach($overlapSuburb->id);

    // ~11.07km from Radius A's origin and ~17.65km from Radius B's — both
    // within each zone's 20km radius, genuinely different (non-tied)
    // distances, and both zones share the same priority, so only distance
    // can decide the winner.
    Suburb::factory()->for($state)->create([
        'name' => 'Nearest Suburb', 'lat' => -37.8300, 'lng' => 145.1200,
    ]);

    // Outside both radius zones and not in any suburb_list.
    Suburb::factory()->for($state)->create([
        'name' => 'No Match Suburb', 'lat' => -38.5000, 'lng' => 146.0000,
    ]);

    // --- Region 2 (Sydney-area coordinates, >600km from Region 1): step 3 ---
    ServiceZone::factory()->for($state)
        ->radius(-33.8000, 151.2000, 15)
        ->create(['name' => 'Region2 Radius Low Priority', 'priority' => 2]);

    ServiceZone::factory()->for($state)
        ->radius(-33.8000, 151.2000, 20)
        ->create(['name' => 'Region2 Radius High Priority', 'priority' => 9]);

    // Sits exactly at both Region 2 zones' shared origin — a genuine
    // distance tie only `priority` can break.
    Suburb::factory()->for($state)->create([
        'name' => 'Tie Suburb', 'lat' => -33.8000, 'lng' => 151.2000,
    ]);
});

it('step 1: an exact suburb_list match wins over an overlapping radius zone', function () {
    $zone = ServiceZone::query()->where('name', 'Region1 Suburb List')->firstOrFail();

    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'Overlap Suburb']);

    $response->assertOk()
        ->assertJsonPath('serviceable', true)
        ->assertJsonPath('service_zone_id', $zone->id)
        ->assertJsonPath('label', 'Region1 Suburb List');
});

it('step 2: among multiple matching radius zones, the nearest origin wins', function () {
    $zone = ServiceZone::query()->where('name', 'Region1 Radius A')->firstOrFail();

    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'Nearest Suburb']);

    $response->assertOk()
        ->assertJsonPath('serviceable', true)
        ->assertJsonPath('service_zone_id', $zone->id)
        ->assertJsonPath('label', 'Region1 Radius A');
});

it('step 3: a genuine radius-distance tie is broken by ServiceZone priority', function () {
    $zone = ServiceZone::query()->where('name', 'Region2 Radius High Priority')->firstOrFail();

    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'Tie Suburb']);

    $response->assertOk()
        ->assertJsonPath('serviceable', true)
        ->assertJsonPath('service_zone_id', $zone->id)
        ->assertJsonPath('label', 'Region2 Radius High Priority');
});

it('step 4: no match at any step reports not serviceable', function () {
    $response = $this->postJson('/api/v1/serviceability', ['suburb' => 'No Match Suburb']);

    $response->assertOk()->assertExactJson([
        'serviceable' => false,
        'service_zone_id' => null,
        'label' => null,
        'suggested_areas' => [],
    ]);
});

it('resolves every fixture in the ladder to its distinct expected zone in the same run', function () {
    $suburbListZone = ServiceZone::query()->where('name', 'Region1 Suburb List')->firstOrFail();
    $radiusZoneA = ServiceZone::query()->where('name', 'Region1 Radius A')->firstOrFail();
    $tieHighPriorityZone = ServiceZone::query()->where('name', 'Region2 Radius High Priority')->firstOrFail();

    $overlap = $this->postJson('/api/v1/serviceability', ['suburb' => 'Overlap Suburb']);
    $nearest = $this->postJson('/api/v1/serviceability', ['suburb' => 'Nearest Suburb']);
    $tie = $this->postJson('/api/v1/serviceability', ['suburb' => 'Tie Suburb']);
    $noMatch = $this->postJson('/api/v1/serviceability', ['suburb' => 'No Match Suburb']);

    expect($overlap->json('service_zone_id'))->toBe($suburbListZone->id)
        ->and($nearest->json('service_zone_id'))->toBe($radiusZoneA->id)
        ->and($tie->json('service_zone_id'))->toBe($tieHighPriorityZone->id)
        ->and($noMatch->json('serviceable'))->toBeFalse();
});
