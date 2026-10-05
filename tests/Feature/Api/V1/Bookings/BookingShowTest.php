<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\Van;
use Carbon\CarbonImmutable;

/**
 * `GET /api/v1/bookings/{booking}` — see
 * docs/architecture/02-api-contract.md's "Booking & capacity endpoints"
 * section. Read-only, reuses `authorizeGuestOrOwner()` verbatim (same dual
 * -auth check already backing `reschedule`/`cancel`), and deliberately
 * omits `manage_token`/`manage_token_issued` — those are creation
 * -response-only fields, a GET never issues or re-surfaces the secret.
 */
function showBookingWithToken(array $overrides = []): array
{
    $plainToken = 'plain-manage-token-for-tests';

    $booking = Booking::factory()->create(array_merge([
        'service_zone_id' => ServiceZone::factory(),
        'technician_id' => Technician::factory(),
        'van_id' => Van::factory(),
        'scheduled_date' => CarbonImmutable::parse('next monday')->toDateString(),
        'status' => BookingStatus::PendingHold,
        'manage_token_hash' => Booking::hashManageToken($plainToken),
    ], $overrides));

    return [$booking, $plainToken];
}

it('lets a guest presenting the correct manage token read the booking', function () {
    [$booking, $token] = showBookingWithToken();

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->getJson("/api/v1/bookings/{$booking->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($booking->id);
    expect($response->json('data.status'))->toBe('pending_hold');
});

it('lets the authenticated owning customer read the booking without a manage token', function () {
    $customer = Customer::factory()->activated()->create();
    [$booking] = showBookingWithToken(['customer_id' => $customer->id, 'manage_token_hash' => null]);

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->getJson("/api/v1/bookings/{$booking->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($booking->id);
});

it('rejects a read with a wrong manage token and no authenticated owner', function () {
    [$booking] = showBookingWithToken();

    $response = $this->withHeader('X-Booking-Manage-Token', 'wrong-token')
        ->getJson("/api/v1/bookings/{$booking->id}");

    $response->assertStatus(403);
});

it('rejects a read with no manage token and no authenticated owner', function () {
    [$booking] = showBookingWithToken();

    $response = $this->getJson("/api/v1/bookings/{$booking->id}");

    $response->assertStatus(403);
});

it('rejects a read from a different authenticated customer', function () {
    $owner = Customer::factory()->activated()->create();
    $intruder = Customer::factory()->activated()->create();
    [$booking] = showBookingWithToken(['customer_id' => $owner->id, 'manage_token_hash' => null]);

    $response = $this->withHeader('Authorization', 'Bearer '.$intruder->createToken('storefront')->plainTextToken)
        ->getJson("/api/v1/bookings/{$booking->id}");

    $response->assertStatus(403);
});

it('404s for an unknown booking id', function () {
    $response = $this->withHeader('X-Booking-Manage-Token', 'whatever')
        ->getJson('/api/v1/bookings/999999');

    $response->assertStatus(404);
});

it('genuinely omits manage_token and manage_token_issued as keys, not just null values', function () {
    [$booking, $token] = showBookingWithToken();

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->getJson("/api/v1/bookings/{$booking->id}");

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['id', 'status', 'scheduled_date', 'slot_start', 'slot_end', 'duration_minutes', 'hold_expires_at', 'cancellation_fee_amount']]);
    expect($response->json('data'))->not->toHaveKey('manage_token');
    expect($response->json('data'))->not->toHaveKey('manage_token_issued');
});
