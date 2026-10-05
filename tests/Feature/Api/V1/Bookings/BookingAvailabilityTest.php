<?php

use App\Models\ServiceZone;
use App\Models\State;
use App\Models\Suburb;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\Van;
use Carbon\CarbonImmutable;

/**
 * `GET /api/v1/booking-availability` (see docs/redesign/api-contract-phase7.md
 * section 4).
 */
beforeEach(function () {
    seedDurationRules();
});

function availabilityMonday(): string
{
    return CarbonImmutable::parse('next monday')->toDateString();
}

/**
 * @return array{zone: ServiceZone, suburb: Suburb}
 */
function availabilityZone(string $start = '07:00:00', string $end = '17:00:00'): array
{
    $state = State::factory()->create(['code' => 'VIC']);
    $zone = ServiceZone::factory()->create(['state_id' => $state->id]);
    $suburb = Suburb::factory()->create(['state_id' => $state->id, 'postcode' => '3121', 'name' => 'Richmond', 'lat' => $zone->origin_lat, 'lng' => $zone->origin_lng]);

    TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id, 'technician_id' => Technician::factory(), 'van_id' => Van::factory(),
        'date' => availabilityMonday(), 'shift_start' => $start, 'shift_end' => $end,
    ]);

    return compact('zone', 'suburb');
}

it('resolves a postcode to a zone and groups slots into windows', function () {
    ['zone' => $zone] = availabilityZone();

    $response = $this->getJson('/api/v1/booking-availability?postcode=3121&date_from='.availabilityMonday().'&date_to='.availabilityMonday());

    $response->assertOk()
        ->assertJsonPath('data.serviceable', true)
        ->assertJsonPath('data.zone.id', $zone->id)
        // base 15 + car 10 x default quantity 4
        ->assertJsonPath('data.duration_minutes', 55)
        ->assertJsonPath('data.days.0.available', true)
        ->assertJsonPath('data.days.0.flexible_available', true)
        ->assertJsonPath('data.flexible.discount_cents', 1000);

    $day = $response->json('data.days.0');
    expect($day['windows']['morning']['available'])->toBeTrue()
        ->and($day['windows']['lunch']['available'])->toBeTrue()
        ->and($day['windows']['afternoon']['available'])->toBeTrue()
        ->and($day['slots_left'])->toBe(count($day['slots']))
        ->and($day['last_slot'])->toBeFalse();

    foreach ($day['windows']['morning']['slots'] as $slot) {
        expect($slot['start'] < '11:00')->toBeTrue();
    }
});

it('marks a day with a single bookable slot as the last slot', function () {
    availabilityZone('09:00:00', '10:00:00');

    $day = $this->getJson('/api/v1/booking-availability?postcode=3121&date_from='.availabilityMonday().'&date_to='.availabilityMonday())->json('data.days.0');

    expect($day['slots_left'])->toBe(1)->and($day['last_slot'])->toBeTrue();
});

it('reports closed days as unavailable without flexible offers', function () {
    availabilityZone();
    $sunday = CarbonImmutable::parse(availabilityMonday())->subDay()->toDateString();

    $this->getJson("/api/v1/booking-availability?postcode=3121&date_from={$sunday}&date_to={$sunday}")
        ->assertOk()
        ->assertJsonPath('data.days.0.available', false)
        ->assertJsonPath('data.days.0.flexible_available', false)
        ->assertJsonPath('data.days.0.flexible_window', null)
        ->assertJsonPath('data.days.0.slots', []);
});

it('returns serviceable false for a place we do not serve', function () {
    $this->getJson('/api/v1/booking-availability?postcode=9999&date_from='.availabilityMonday().'&date_to='.availabilityMonday())
        ->assertOk()
        ->assertExactJson(['data' => [
            'serviceable' => false, 'zone' => null, 'duration_minutes' => 0,
            'flexible' => ['available' => false, 'discount_cents' => 0, 'label' => null], 'days' => [],
        ]]);
});

it('accepts a zone id and a suburb name', function () {
    ['zone' => $zone] = availabilityZone();
    $query = 'date_from='.availabilityMonday().'&date_to='.availabilityMonday();

    $this->getJson("/api/v1/booking-availability?zone={$zone->id}&{$query}")->assertOk()->assertJsonPath('data.zone.id', $zone->id);
    $this->getJson("/api/v1/booking-availability?suburb=richmond&{$query}")->assertOk()->assertJsonPath('data.zone.id', $zone->id);
});

it('validates location and dates', function () {
    $this->getJson('/api/v1/booking-availability?date_from=2026-10-05&date_to=2026-10-06')->assertUnprocessable();
    $this->getJson('/api/v1/booking-availability?postcode=3121&date_from=2026-10-05&date_to=2026-11-30')->assertUnprocessable()->assertJsonValidationErrors('date_to');
    $this->getJson('/api/v1/booking-availability?postcode=3121&date_from=2026-10-05&date_to=2026-10-06&quantity=20')->assertUnprocessable()->assertJsonValidationErrors('quantity');
});
