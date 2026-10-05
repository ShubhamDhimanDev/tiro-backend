<?php

use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\User;
use App\Models\Van;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Covers App\Http\Controllers\Admin\Bookings\TechnicianShiftController and
 * App\Http\Requests\Admin\Bookings\TechnicianShiftRequest — the capacity
 * source of truth the booking engine and dispatch board both read. The
 * `after()` rule backstops the `(technician_id, date, shift_start)` unique
 * index with a friendlier message, and separately rejects any genuine
 * time-range overlap (a different `shift_start` whose interval still
 * intersects an existing shift) so a technician's roster can't accidentally
 * contain two overlapping shifts; the update path's self-exclusion is
 * asserted separately since getting that wrong would make every update of
 * an unrelated field on an existing shift fail.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function shiftRosterManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('operations');

    return $user;
}

function validShiftPayload(array $overrides = []): array
{
    return array_merge([
        'technician_id' => Technician::factory()->create()->id,
        'van_id' => Van::factory()->create()->id,
        'service_zone_id' => ServiceZone::factory()->create()->id,
        'date' => now()->addDay()->toDateString(),
        'shift_start' => '09:00',
        'shift_end' => '17:00',
        'status' => Status::Active->value,
    ], $overrides);
}

test('a bookings.manage user can create a shift', function () {
    $admin = shiftRosterManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.shifts.store'), validShiftPayload());

    $response->assertRedirect();
    $this->assertDatabaseCount('technician_shifts', 1);
});

test('a bookings.manage user can update a shift', function () {
    $admin = shiftRosterManager();
    $shift = TechnicianShift::factory()->create(['shift_start' => '09:00:00', 'shift_end' => '17:00:00']);

    $response = $this->actingAs($admin)->put(route('admin.bookings.shifts.update', $shift), validShiftPayload([
        'technician_id' => $shift->technician_id,
        'van_id' => $shift->van_id,
        'service_zone_id' => $shift->service_zone_id,
        'date' => $shift->date->toDateString(),
        'shift_start' => '10:00',
        'shift_end' => '18:00',
    ]));

    $response->assertRedirect();
    expect($shift->refresh()->shift_start)->toBe('10:00:00');
});

test('shift_end must be after shift_start, with a user-visible message', function () {
    $admin = shiftRosterManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.shifts.store'), validShiftPayload([
        'shift_start' => '17:00',
        'shift_end' => '09:00',
    ]));

    $response->assertSessionHasErrors(['shift_end' => 'Shift end must be after shift start.']);
});

test('a second shift for the same technician/date/start time is rejected with a user-visible message', function () {
    $admin = shiftRosterManager();
    $technician = Technician::factory()->create();
    $date = now()->addDay()->toDateString();

    TechnicianShift::factory()->create([
        'technician_id' => $technician->id,
        'date' => $date,
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    $response = $this->actingAs($admin)->post(route('admin.bookings.shifts.store'), validShiftPayload([
        'technician_id' => $technician->id,
        'date' => $date,
        'shift_start' => '09:00',
        'shift_end' => '13:00',
    ]));

    $response->assertSessionHasErrors([
        'shift_start' => 'This technician already has a shift starting at this time on this date.',
    ]);
});

test('a genuine sub-range overlap with a different start time is rejected with a user-visible message', function () {
    $admin = shiftRosterManager();
    $technician = Technician::factory()->create();
    $date = now()->addDay()->toDateString();

    TechnicianShift::factory()->create([
        'technician_id' => $technician->id,
        'date' => $date,
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    // 09:15-13:00 sits entirely inside the existing 09:00-17:00 shift for
    // the same technician/date — a different shift_start, so only a true
    // interval-overlap test (not the exact-duplicate check) catches it.
    $response = $this->actingAs($admin)->post(route('admin.bookings.shifts.store'), validShiftPayload([
        'technician_id' => $technician->id,
        'date' => $date,
        'shift_start' => '09:15',
        'shift_end' => '13:00',
    ]));

    $response->assertSessionHasErrors([
        'shift_start' => 'This technician already has an overlapping shift on this date.',
    ]);
    $this->assertDatabaseCount('technician_shifts', 1);
});

test('a partial overlap that starts before and ends inside an existing shift is rejected', function () {
    $admin = shiftRosterManager();
    $technician = Technician::factory()->create();
    $date = now()->addDay()->toDateString();

    TechnicianShift::factory()->create([
        'technician_id' => $technician->id,
        'date' => $date,
        'shift_start' => '12:00:00',
        'shift_end' => '17:00:00',
    ]);

    // 09:00-13:00 overlaps the last hour of the existing 12:00-17:00 shift.
    $response = $this->actingAs($admin)->post(route('admin.bookings.shifts.store'), validShiftPayload([
        'technician_id' => $technician->id,
        'date' => $date,
        'shift_start' => '09:00',
        'shift_end' => '13:00',
    ]));

    $response->assertSessionHasErrors([
        'shift_start' => 'This technician already has an overlapping shift on this date.',
    ]);
    $this->assertDatabaseCount('technician_shifts', 1);
});

test('back-to-back shifts that touch but do not overlap are still allowed (split shifts)', function () {
    $admin = shiftRosterManager();
    $technician = Technician::factory()->create();
    $date = now()->addDay()->toDateString();

    TechnicianShift::factory()->create([
        'technician_id' => $technician->id,
        'date' => $date,
        'shift_start' => '09:00:00',
        'shift_end' => '13:00:00',
    ]);

    // Second shift starts exactly when the first ends — legitimate split
    // shift, must not be treated as an overlap.
    $response = $this->actingAs($admin)->post(route('admin.bookings.shifts.store'), validShiftPayload([
        'technician_id' => $technician->id,
        'date' => $date,
        'shift_start' => '13:00',
        'shift_end' => '17:00',
    ]));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors('shift_start');
    $this->assertDatabaseCount('technician_shifts', 2);
});

test('updating a shift without changing its technician/date/start time does not trip the overlap rule against itself', function () {
    $admin = shiftRosterManager();
    $shift = TechnicianShift::factory()->create([
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    $response = $this->actingAs($admin)->put(route('admin.bookings.shifts.update', $shift), validShiftPayload([
        'technician_id' => $shift->technician_id,
        'van_id' => $shift->van_id,
        'service_zone_id' => $shift->service_zone_id,
        'date' => $shift->date->toDateString(),
        'shift_start' => '09:00',
        'shift_end' => '16:00',
        'status' => Status::Inactive->value,
    ]));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors('shift_start');
    expect($shift->refresh())
        ->shift_end->toBe('16:00:00')
        ->status->toBe(Status::Inactive);
});

test('an unrelated shift for a different technician at the same date/start time is not treated as an overlap', function () {
    $admin = shiftRosterManager();
    $date = now()->addDay()->toDateString();

    TechnicianShift::factory()->create([
        'date' => $date,
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    $response = $this->actingAs($admin)->post(route('admin.bookings.shifts.store'), validShiftPayload([
        'date' => $date,
        'shift_start' => '09:00',
        'shift_end' => '17:00',
    ]));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors('shift_start');
});

test('technician_id must reference an existing technician', function () {
    $admin = shiftRosterManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.shifts.store'), validShiftPayload([
        'technician_id' => 999999,
    ]));

    $response->assertSessionHasErrors('technician_id');
});

test('a user without bookings.manage is forbidden from viewing, creating, or updating shifts', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->assignRole('ecommerce');
    $shift = TechnicianShift::factory()->create();

    $this->actingAs($viewer)->get(route('admin.bookings.shifts.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('admin.bookings.shifts.store'), validShiftPayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('admin.bookings.shifts.update', $shift), validShiftPayload())->assertForbidden();
});
