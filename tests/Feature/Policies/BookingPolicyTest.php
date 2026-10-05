<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * See docs/architecture/07-admin-auth-permissions.md §3.1 footnote 2 — the
 * backend half of the technician-scoped-login task deferred from Phase 0.
 * `bookings.view-own` scopes a Technician's staff login to their own
 * `technician_id` within a near-term date window; `bookings.manage`/`view`
 * holders see every row.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function technicianUser(): array
{
    $user = User::factory()->create();
    $user->assignRole('technician');
    $technician = Technician::factory()->create(['user_id' => $user->id]);

    return [$user, $technician];
}

it('allows a technician to view their own near-term booking', function () {
    [$user, $technician] = technicianUser();
    $booking = Booking::factory()->create(['technician_id' => $technician->id, 'scheduled_date' => now()->addDays(2)]);

    expect(Gate::forUser($user)->allows('view', $booking))->toBeTrue();
});

it('denies a technician viewing another technician\'s booking', function () {
    [$user] = technicianUser();
    $otherTechnician = Technician::factory()->create();
    $booking = Booking::factory()->create(['technician_id' => $otherTechnician->id, 'scheduled_date' => now()->addDays(2)]);

    expect(Gate::forUser($user)->allows('view', $booking))->toBeFalse();
});

it('denies a technician viewing their own booking outside the near-term window', function () {
    [$user, $technician] = technicianUser();
    $booking = Booking::factory()->create(['technician_id' => $technician->id, 'scheduled_date' => now()->addDays(365)]);

    expect(Gate::forUser($user)->allows('view', $booking))->toBeFalse();
});

it('denies a technician-role user with no paired Technician row', function () {
    $user = User::factory()->create();
    $user->assignRole('technician');
    $booking = Booking::factory()->create(['scheduled_date' => now()->addDay()]);

    expect(Gate::forUser($user)->allows('view', $booking))->toBeFalse();
});

it('allows an operations user (bookings.manage) to view any booking regardless of technician or date', function () {
    $user = User::factory()->create();
    $user->assignRole('operations');
    $booking = Booking::factory()->create(['scheduled_date' => now()->addDays(365), 'status' => BookingStatus::Confirmed]);

    expect(Gate::forUser($user)->allows('view', $booking))->toBeTrue();
});

it('denies a staff user with no bookings permission at all', function () {
    $user = User::factory()->create();
    $user->assignRole('ecommerce');
    $booking = Booking::factory()->create(['scheduled_date' => now()->addDay()]);

    expect(Gate::forUser($user)->allows('view', $booking))->toBeFalse();
});

it('allows the owning customer to manage their own booking', function () {
    $customer = Customer::factory()->activated()->create();
    $booking = Booking::factory()->create(['customer_id' => $customer->id]);

    expect(Gate::forUser($customer)->allows('manage', $booking))->toBeTrue();
});

it('denies a customer managing someone else\'s booking', function () {
    $owner = Customer::factory()->activated()->create();
    $other = Customer::factory()->activated()->create();
    $booking = Booking::factory()->create(['customer_id' => $owner->id]);

    expect(Gate::forUser($other)->allows('manage', $booking))->toBeFalse();
});

it('denies a customer managing a guest booking with no customer_id at all', function () {
    $customer = Customer::factory()->activated()->create();
    $booking = Booking::factory()->create(['customer_id' => null]);

    expect(Gate::forUser($customer)->allows('manage', $booking))->toBeFalse();
});
