<?php

use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\Van;
use App\Services\Bookings\SameDayCutoffPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * The same-day rule: a fitting can be booked for today only before the daily
 * cutoff (09:00 business time); after it the earliest day is tomorrow.
 */
beforeEach(function () {
    seedDurationRules();
    Queue::fake();
});

/** A moment in business time, e.g. `cutoffAt('2026-11-05 08:30')`. */
function cutoffAt(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, config('bookings.timezone'));
}

/** A zone with a technician on shift 07:00-17:00 on today and tomorrow (business time). */
function cutoffZone(): ServiceZone
{
    $zone = ServiceZone::factory()->create();

    foreach ([CarbonImmutable::now(config('bookings.timezone')), CarbonImmutable::now(config('bookings.timezone'))->addDay()] as $day) {
        TechnicianShift::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => Technician::factory(),
            'van_id' => Van::factory(),
            'date' => $day->toDateString(),
            'shift_start' => '07:00:00',
            'shift_end' => '17:00:00',
        ]);
    }

    return $zone;
}

it('opens same-day booking only before the cutoff', function () {
    $policy = new SameDayCutoffPolicy;

    expect($policy->sameDayOpen(cutoffAt('2026-11-05 08:59')))->toBeTrue()
        ->and($policy->sameDayOpen(cutoffAt('2026-11-05 09:00')))->toBeFalse()
        ->and($policy->sameDayOpen(cutoffAt('2026-11-05 14:00')))->toBeFalse()
        ->and($policy->earliestDate(cutoffAt('2026-11-05 08:30')))->toBe('2026-11-05')
        ->and($policy->earliestDate(cutoffAt('2026-11-05 09:01')))->toBe('2026-11-06');
});

it('uses the business timezone, not UTC, for "today" and the cutoff', function () {
    $policy = new SameDayCutoffPolicy;

    // 22:30 UTC on the 4th is 09:30 on the 5th in Melbourne: past the cutoff, and "today" is the 5th.
    $utc = CarbonImmutable::parse('2026-11-04 22:30', 'UTC');

    expect($policy->today($utc))->toBe('2026-11-05')
        ->and($policy->sameDayOpen($utc))->toBeFalse()
        ->and($policy->earliestDate($utc))->toBe('2026-11-06');
});

it('drops same-day slots that start too soon, and everything once the cutoff has passed', function () {
    $policy = new SameDayCutoffPolicy;
    $slots = [['start' => '08:00', 'end' => '09:00'], ['start' => '09:30', 'end' => '10:30'], ['start' => '13:00', 'end' => '14:00']];

    // 08:30 + 60 minute lead => the earliest bookable start is 09:30.
    expect($policy->filterSlots('2026-11-05', $slots, cutoffAt('2026-11-05 08:30')))->toBe([$slots[1], $slots[2]])
        ->and($policy->filterSlots('2026-11-05', $slots, cutoffAt('2026-11-05 09:15')))->toBe([])
        ->and($policy->filterSlots('2026-11-06', $slots, cutoffAt('2026-11-05 09:15')))->toBe($slots)
        ->and($policy->filterSlots('2026-11-04', $slots, cutoffAt('2026-11-05 08:30')))->toBe([]);
});

it('offers today before the cutoff on the slots endpoint', function () {
    $this->travelTo(cutoffAt('2026-11-05 07:30'));
    $zone = cutoffZone();

    $response = $this->getJson('/api/v1/booking-slots?'.http_build_query([
        'zone' => $zone->id, 'date_from' => '2026-11-05', 'date_to' => '2026-11-06',
    ]));

    $response->assertOk()
        ->assertJsonPath('data.booking_rules.same_day_open', true)
        ->assertJsonPath('data.booking_rules.earliest_date', '2026-11-05')
        ->assertJsonPath('data.booking_rules.same_day_cutoff', '09:00');

    // 07:30 + 60 minutes => nothing earlier than 08:30 today.
    expect($response->json('data.days.0.slots.0.start'))->toBe('08:30')
        ->and($response->json('data.days.1.slots.0.start'))->toBe('07:00');
});

it('shows no slots today after the cutoff but keeps tomorrow open', function () {
    $this->travelTo(cutoffAt('2026-11-05 09:30'));
    $zone = cutoffZone();

    $response = $this->getJson('/api/v1/booking-slots?'.http_build_query([
        'zone' => $zone->id, 'date_from' => '2026-11-05', 'date_to' => '2026-11-06',
    ]));

    $response->assertOk()
        ->assertJsonPath('data.booking_rules.same_day_open', false)
        ->assertJsonPath('data.booking_rules.earliest_date', '2026-11-06')
        ->assertJsonPath('data.days.0.slots', []);

    expect($response->json('data.days.1.slots'))->not->toBeEmpty();
});

it('refuses to create a same-day booking after the cutoff, and accepts tomorrow', function () {
    $this->travelTo(cutoffAt('2026-11-05 09:30'));
    $zone = cutoffZone();

    $today = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, ['scheduled_date' => '2026-11-05', 'slot_start' => '13:00']));

    $today->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_date')
        ->assertJsonPath('booking_rules.earliest_date', '2026-11-06')
        ->assertJsonPath('booking_rules.same_day_open', false);

    $tomorrow = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, ['scheduled_date' => '2026-11-06', 'slot_start' => '09:00']));

    $tomorrow->assertCreated();
});

it('also refuses a flexible same-day booking after the cutoff', function () {
    $this->travelTo(cutoffAt('2026-11-05 09:30'));
    $zone = cutoffZone();

    $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, ['scheduled_date' => '2026-11-05', 'slot_start' => null, 'flexible' => true]))
        ->assertUnprocessable();
});

it('allows a same-day booking made before the cutoff', function () {
    $this->travelTo(cutoffAt('2026-11-05 08:00'));
    $zone = cutoffZone();

    $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, ['scheduled_date' => '2026-11-05', 'slot_start' => '13:00']))
        ->assertCreated();
});

it('reports the rule on the availability endpoint and blocks today after the cutoff', function () {
    $this->travelTo(cutoffAt('2026-11-05 10:00'));
    $zone = cutoffZone();

    $response = $this->getJson('/api/v1/booking-availability?'.http_build_query([
        'zone' => $zone->id, 'date_from' => '2026-11-05', 'date_to' => '2026-11-06',
    ]));

    $response->assertOk()
        ->assertJsonPath('data.booking_rules.same_day_open', false)
        ->assertJsonPath('data.days.0.available', false)
        ->assertJsonPath('data.days.1.available', true);
});
