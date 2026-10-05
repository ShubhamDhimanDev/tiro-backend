<?php

use App\Enums\BookingStatus;
use App\Enums\Status;
use App\Models\Booking;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\Van;
use App\Services\Bookings\SlotComputationService;
use Carbon\CarbonImmutable;

/**
 * Exercises docs/architecture/04-booking-capacity-engine.md's "Slot
 * computation" algorithm (steps 1-7) directly against the service, isolated
 * from the HTTP layer.
 */
function nextMonday(): CarbonImmutable
{
    return CarbonImmutable::parse('next monday')->startOfDay();
}

function makeZoneWithShift(array $shiftOverrides = [], array $zoneOverrides = []): array
{
    $zone = ServiceZone::factory()->create($zoneOverrides);
    $technician = Technician::factory()->create();
    $van = Van::factory()->create();

    $shift = TechnicianShift::factory()->create(array_merge([
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'service_zone_id' => $zone->id,
        'date' => nextMonday()->toDateString(),
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
        'status' => Status::Active,
    ], $shiftOverrides));

    return compact('zone', 'technician', 'van', 'shift');
}

it('returns 15-minute grid-aligned slots that fit inside both the shift window and operating hours', function () {
    ['zone' => $zone] = makeZoneWithShift();

    $slots = app(SlotComputationService::class)->candidateSlotsForDate($zone, nextMonday(), 45, false);

    expect($slots)->not->toBeEmpty();
    expect($slots[0])->toBe(['start' => '09:00', 'end' => '09:45']);
    // Every slot must fit inside the 09:00-17:00 shift AND the zone's
    // 08:00-18:00 Monday operating hours (the zone factory's default).
    foreach ($slots as $slot) {
        expect($slot['start'])->toBeGreaterThanOrEqual('09:00');
        expect($slot['end'])->toBeLessThanOrEqual('17:00');
    }
});

it('returns no slots when the zone is closed that weekday', function () {
    ['zone' => $zone] = makeZoneWithShift();

    // Sunday is null in the default operating_hours fixture.
    $sunday = nextMonday()->subDay();

    $slots = app(SlotComputationService::class)->candidateSlotsForDate($zone, $sunday, 45, false);

    expect($slots)->toBe([]);
});

it('excludes the travel-buffered window around an existing occupying booking', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = makeZoneWithShift();

    Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'scheduled_date' => nextMonday()->toDateString(),
        'slot_start' => '10:00:00',
        'slot_end' => '10:45:00',
        'status' => BookingStatus::PendingHold,
    ]);

    $slots = app(SlotComputationService::class)->candidateSlotsForDate($zone, nextMonday(), 30, false);

    // Occupied window (with the 20-minute buffer either side) is
    // 09:40-11:05 — no returned slot may start inside it nor end after it
    // starts within that span.
    foreach ($slots as $slot) {
        $overlapsBufferedBooking = $slot['start'] < '11:05' && $slot['end'] > '09:40';
        expect($overlapsBufferedBooking)->toBeFalse();
    }

    expect($slots)->toContain(['start' => '09:00', 'end' => '09:30']);
    // The buffered window ends at 11:05 (10:45 + 20min buffer), but slot
    // starts are aligned to the absolute 15-minute grid, so the first
    // available slot after it starts at 11:15, not 11:05.
    expect($slots)->toContain(['start' => '11:15', 'end' => '11:45']);
});

it('subtracts more than one occupying booking, leaving the free window sandwiched between them', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = makeZoneWithShift();

    // Two separate occupying bookings on the same shift — the algorithm
    // must reduce the shift's open interval by both, not just the last one
    // applied, leaving a free gap between their buffered windows.
    Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'scheduled_date' => nextMonday()->toDateString(),
        'slot_start' => '09:00:00',
        'slot_end' => '09:30:00',
        'status' => BookingStatus::Confirmed,
    ]);

    Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'scheduled_date' => nextMonday()->toDateString(),
        'slot_start' => '12:00:00',
        'slot_end' => '12:30:00',
        'status' => BookingStatus::PendingHold,
    ]);

    $slots = app(SlotComputationService::class)->candidateSlotsForDate($zone, nextMonday(), 30, false);

    // Buffered windows: 08:40-09:50 and 11:40-12:50. A slot sandwiched
    // between the two survives (10:00-10:30); neither buffered window itself
    // yields a candidate.
    expect($slots)->toContain(['start' => '10:00', 'end' => '10:30']);
    foreach ($slots as $slot) {
        $overlapsFirstBooking = $slot['start'] < '09:50' && $slot['end'] > '08:40';
        $overlapsSecondBooking = $slot['start'] < '12:50' && $slot['end'] > '11:40';
        expect($overlapsFirstBooking)->toBeFalse();
        expect($overlapsSecondBooking)->toBeFalse();
    }
});

it('does not let cancelled/expired bookings occupy a window', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = makeZoneWithShift();

    Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'scheduled_date' => nextMonday()->toDateString(),
        'slot_start' => '10:00:00',
        'slot_end' => '10:45:00',
        'status' => BookingStatus::Cancelled,
    ]);

    $slots = app(SlotComputationService::class)->candidateSlotsForDate($zone, nextMonday(), 30, false);

    expect($slots)->toContain(['start' => '10:00', 'end' => '10:30']);
});

it('excludes a van entirely once it hits its daily job cap, independent of remaining open time', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = makeZoneWithShift(zoneOverrides: []);
    $van->update(['max_jobs_per_day' => 1]);

    Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'scheduled_date' => nextMonday()->toDateString(),
        'slot_start' => '09:00:00',
        'slot_end' => '09:15:00',
        'status' => BookingStatus::PendingHold,
    ]);

    $slots = app(SlotComputationService::class)->candidateSlotsForDate($zone, nextMonday(), 30, false);

    expect($slots)->toBe([]);
});

it('filters out shifts whose van lacks alignment equipment when the booking requires it', function () {
    ['zone' => $zone, 'van' => $van] = makeZoneWithShift();

    $slotsWithoutAlignment = app(SlotComputationService::class)->candidateSlotsForDate($zone, nextMonday(), 30, true);
    expect($slotsWithoutAlignment)->toBe([]);

    $van->update(['has_alignment_equipment' => true]);

    $slotsWithAlignment = app(SlotComputationService::class)->candidateSlotsForDate($zone, nextMonday(), 30, true);
    expect($slotsWithAlignment)->not->toBeEmpty();
});

it('unions candidates across multiple technicians into one deduplicated list', function () {
    $zone = ServiceZone::factory()->create();

    $technicianA = Technician::factory()->create();
    $vanA = Van::factory()->create();
    TechnicianShift::factory()->create([
        'technician_id' => $technicianA->id, 'van_id' => $vanA->id, 'service_zone_id' => $zone->id,
        'date' => nextMonday()->toDateString(), 'shift_start' => '09:00:00', 'shift_end' => '10:00:00',
    ]);

    $technicianB = Technician::factory()->create();
    $vanB = Van::factory()->create();
    TechnicianShift::factory()->create([
        'technician_id' => $technicianB->id, 'van_id' => $vanB->id, 'service_zone_id' => $zone->id,
        'date' => nextMonday()->toDateString(), 'shift_start' => '09:00:00', 'shift_end' => '10:00:00',
    ]);

    $slots = app(SlotComputationService::class)->candidateSlotsForDate($zone, nextMonday(), 30, false);

    // Both technicians offer an identical 09:00-09:30 slot — the union must
    // not list it twice.
    expect(collect($slots)->where('start', '09:00')->count())->toBe(1);
});

it('excludes a technician from eligibility once assigned elsewhere, via the exclude-booking-id parameter used for reschedule re-checks', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = makeZoneWithShift();

    $booking = Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'scheduled_date' => nextMonday()->toDateString(),
        'slot_start' => '09:00:00',
        'slot_end' => '09:45:00',
        'status' => BookingStatus::PendingHold,
    ]);

    $service = app(SlotComputationService::class);

    // Without excluding the booking, its own slot self-conflicts.
    expect($service->isTechnicianSlotFree($zone, $technician->id, nextMonday(), '09:00', 45, false))->toBeFalse();

    // Excluding it, the booking can be "rescheduled" onto the exact window
    // it already occupies.
    expect($service->isTechnicianSlotFree($zone, $technician->id, nextMonday(), '09:00', 45, false, excludeBookingId: $booking->id))->toBeTrue();
});
