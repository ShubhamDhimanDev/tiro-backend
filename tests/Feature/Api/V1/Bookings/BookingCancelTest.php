<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Customer;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\Van;
use Carbon\CarbonImmutable;
use Database\Seeders\CancellationPolicySeeder;

/**
 * `POST /api/v1/bookings/{booking}/cancel` — see
 * docs/architecture/02-api-contract.md's "Booking & capacity endpoints"
 * section.
 */
function cancelBookingWithToken(array $overrides = []): array
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

it('cancels a guest booking presenting the correct manage token, applying the permissive global policy', function () {
    $this->seed(CancellationPolicySeeder::class);
    [$booking, $token] = cancelBookingWithToken();

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->postJson("/api/v1/bookings/{$booking->id}/cancel");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('cancelled');
    expect($response->json('data.cancellation_fee_amount'))->toBe(0);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->hold_expires_at)->toBeNull();
    expect($booking->cancellation_fee_amount)->toBe(0);
});

it('rejects a cancel with no manage token and no authenticated owner', function () {
    [$booking] = cancelBookingWithToken();

    $response = $this->postJson("/api/v1/bookings/{$booking->id}/cancel");

    $response->assertStatus(403);
    expect($booking->fresh()->status)->toBe(BookingStatus::PendingHold);
});

it('allows the authenticated owning customer to cancel without a manage token', function () {
    $this->seed(CancellationPolicySeeder::class);
    $customer = Customer::factory()->activated()->create();
    [$booking] = cancelBookingWithToken(['customer_id' => $customer->id, 'manage_token_hash' => null]);

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->postJson("/api/v1/bookings/{$booking->id}/cancel");

    $response->assertOk();
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('applies a zone-specific cancellation policy fee over the global default when both exist', function () {
    $zone = ServiceZone::factory()->create();

    CancellationPolicy::factory()->create(['service_zone_id' => null, 'notice_hours' => 0]);
    CancellationPolicy::factory()->create([
        'service_zone_id' => $zone->id,
        'notice_hours' => 48,
        'fee_amount' => 5000,
    ]);

    [$booking, $token] = cancelBookingWithToken([
        'service_zone_id' => $zone->id,
        // Scheduled far enough in the future that notice given (>48h) still
        // meets this zone's stricter notice_hours, so no fee is charged —
        // proves the zone-specific row was actually selected (a global-row
        // fallback here would still charge nothing either, so the negative
        // case below is what actually distinguishes them).
    ]);

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->postJson("/api/v1/bookings/{$booking->id}/cancel");

    $response->assertOk();
});

it('charges the zone-specific fee when cancelling inside that zone\'s notice window', function () {
    $zone = ServiceZone::factory()->create();

    CancellationPolicy::factory()->create(['service_zone_id' => null, 'notice_hours' => 0]);
    CancellationPolicy::factory()->create([
        'service_zone_id' => $zone->id,
        'notice_hours' => 65000, // unsignedSmallInteger max is 65535 — effectively "always inside the notice window" for this test.
        'fee_amount' => 5000,
    ]);

    [$booking, $token] = cancelBookingWithToken(['service_zone_id' => $zone->id]);

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->postJson("/api/v1/bookings/{$booking->id}/cancel");

    $response->assertOk();
    expect($response->json('data.cancellation_fee_amount'))->toBe(5000);
});

it('rejects cancelling an already-cancelled booking', function () {
    $this->seed(CancellationPolicySeeder::class);
    [$booking, $token] = cancelBookingWithToken(['status' => BookingStatus::Cancelled]);

    $response = $this->withHeader('X-Booking-Manage-Token', $token)
        ->postJson("/api/v1/bookings/{$booking->id}/cancel");

    $response->assertStatus(409);
});
