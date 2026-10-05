<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\Van;
use Carbon\CarbonImmutable;

/**
 * `PATCH /api/v1/bookings/{booking}/reschedule` — see
 * docs/architecture/02-api-contract.md's "Booking & capacity endpoints"
 * section.
 */
beforeEach(function () {
    seedDurationRules();
});

function rescheduleMonday(): string
{
    return CarbonImmutable::parse('next monday')->toDateString();
}

/**
 * @return array{zone: ServiceZone, technician: Technician, van: Van}
 */
function rescheduleFixture(): array
{
    $zone = ServiceZone::factory()->create();
    $technician = Technician::factory()->create();
    $van = Van::factory()->create();

    TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'date' => rescheduleMonday(),
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    return compact('zone', 'technician', 'van');
}

function guestBookingWithToken(ServiceZone $zone, Technician $technician, Van $van, string $slotStart = '09:00:00', int $duration = 15): array
{
    $plainToken = 'plain-manage-token-for-tests';

    $booking = Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'scheduled_date' => rescheduleMonday(),
        'slot_start' => $slotStart,
        'slot_end' => CarbonImmutable::createFromFormat('H:i:s', $slotStart)->addMinutes($duration)->format('H:i:s'),
        'duration_minutes' => $duration,
        'status' => BookingStatus::PendingHold,
        'manage_token_hash' => Booking::hashManageToken($plainToken),
    ]);

    return [$booking, $plainToken];
}

it('reschedules a guest booking presenting the correct manage token', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = rescheduleFixture();
    [$booking, $token] = guestBookingWithToken($zone, $technician, $van);

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->patchJson("/api/v1/bookings/{$booking->id}/reschedule", [
            'scheduled_date' => rescheduleMonday(),
            'slot_start' => '11:00',
        ]);

    $response->assertOk();
    expect($response->json('data.slot_start'))->toBe('11:00');

    expect($booking->fresh()->slot_start)->toBe('11:00:00');
});

it('rejects a reschedule with no manage token and no authenticated owner', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = rescheduleFixture();
    [$booking] = guestBookingWithToken($zone, $technician, $van);

    $response = $this->patchJson("/api/v1/bookings/{$booking->id}/reschedule", [
        'scheduled_date' => rescheduleMonday(),
        'slot_start' => '11:00',
    ]);

    $response->assertStatus(403);
});

it('rejects a reschedule with a wrong manage token', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = rescheduleFixture();
    [$booking] = guestBookingWithToken($zone, $technician, $van);

    $response = $this->withHeader('X-Booking-Manage-Token', 'wrong-token')
        ->patchJson("/api/v1/bookings/{$booking->id}/reschedule", [
            'scheduled_date' => rescheduleMonday(),
            'slot_start' => '11:00',
        ]);

    $response->assertStatus(403);
});

it('allows the authenticated owning customer to reschedule without a manage token', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = rescheduleFixture();
    $customer = Customer::factory()->activated()->create();

    $booking = Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'customer_id' => $customer->id,
        'scheduled_date' => rescheduleMonday(),
        'slot_start' => '09:00:00',
        'slot_end' => '09:15:00',
        'duration_minutes' => 15,
        'status' => BookingStatus::Confirmed,
        'manage_token_hash' => null,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->patchJson("/api/v1/bookings/{$booking->id}/reschedule", [
            'scheduled_date' => rescheduleMonday(),
            'slot_start' => '13:00',
        ]);

    $response->assertOk();
    expect($booking->fresh()->slot_start)->toBe('13:00:00');
});

it('rejects a reschedule from a different authenticated customer', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = rescheduleFixture();
    $owner = Customer::factory()->activated()->create();
    $intruder = Customer::factory()->activated()->create();

    $booking = Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'customer_id' => $owner->id,
        'scheduled_date' => rescheduleMonday(),
        'slot_start' => '09:00:00',
        'slot_end' => '09:15:00',
        'duration_minutes' => 15,
        'status' => BookingStatus::Confirmed,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$intruder->createToken('storefront')->plainTextToken)
        ->patchJson("/api/v1/bookings/{$booking->id}/reschedule", [
            'scheduled_date' => rescheduleMonday(),
            'slot_start' => '13:00',
        ]);

    $response->assertStatus(403);
});

it('re-validates through the slot engine and returns 409 when the target slot is unavailable', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = rescheduleFixture();
    [$booking, $token] = guestBookingWithToken($zone, $technician, $van);

    // Occupy 13:00-13:15 with another booking for the same technician.
    Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'scheduled_date' => rescheduleMonday(),
        'slot_start' => '13:00:00',
        'slot_end' => '13:15:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->patchJson("/api/v1/bookings/{$booking->id}/reschedule", [
            'scheduled_date' => rescheduleMonday(),
            'slot_start' => '13:00',
        ]);

    $response->assertStatus(409);
    expect($booking->fresh()->slot_start)->toBe('09:00:00');
});

it('allows rescheduling onto the exact window the booking already occupies (self-exclusion)', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = rescheduleFixture();
    [$booking, $token] = guestBookingWithToken($zone, $technician, $van);

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->patchJson("/api/v1/bookings/{$booking->id}/reschedule", [
            'scheduled_date' => rescheduleMonday(),
            'slot_start' => '09:00',
        ]);

    $response->assertOk();
});

it('rejects rescheduling a cancelled booking', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = rescheduleFixture();
    [$booking, $token] = guestBookingWithToken($zone, $technician, $van);
    $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->patchJson("/api/v1/bookings/{$booking->id}/reschedule", [
            'scheduled_date' => rescheduleMonday(),
            'slot_start' => '11:00',
        ]);

    $response->assertStatus(409);
});
