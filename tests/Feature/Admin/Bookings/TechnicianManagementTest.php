<?php

use App\Enums\Status;
use App\Enums\TechnicianEmploymentType;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Covers App\Http\Controllers\Admin\Bookings\TechnicianController and
 * App\Http\Requests\Admin\Bookings\TechnicianRequest. `user_id` is
 * deliberately absent from the request's rule set (login provisioning is a
 * separate, explicit step — see TechnicianLoginControllerTest) so one test
 * below proves that claim: a payload that tries to smuggle `user_id` in
 * cannot set it via this endpoint.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function rosterManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('operations');

    return $user;
}

function validTechnicianPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jamie Fitter',
        'employment_type' => TechnicianEmploymentType::Employee->value,
        'certifications' => ['tyre_fitting'],
        'status' => Status::Active->value,
    ], $overrides);
}

test('a bookings.manage user can create a technician', function () {
    $admin = rosterManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.technicians.store'), validTechnicianPayload([
        'name' => 'Alex Wrench',
    ]));

    $response->assertRedirect();
    $this->assertDatabaseHas('technicians', ['name' => 'Alex Wrench']);
});

test('a bookings.manage user can update a technician', function () {
    $admin = rosterManager();
    $technician = Technician::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($admin)->put(route('admin.bookings.technicians.update', $technician), validTechnicianPayload([
        'name' => 'New Name',
    ]));

    $response->assertRedirect();
    expect($technician->refresh()->name)->toBe('New Name');
});

test('an invalid employment_type is rejected', function () {
    $admin = rosterManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.technicians.store'), validTechnicianPayload([
        'employment_type' => 'not-a-real-type',
    ]));

    $response->assertSessionHasErrors('employment_type');
});

test('certifications must be an array of strings no longer than 100 characters', function () {
    $admin = rosterManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.technicians.store'), validTechnicianPayload([
        'certifications' => [str_repeat('a', 101)],
    ]));

    $response->assertSessionHasErrors('certifications.0');
});

test('user_id cannot be set through the technician roster form, even if the payload includes it', function () {
    $admin = rosterManager();
    $otherUser = User::factory()->withTwoFactor()->create();

    $response = $this->actingAs($admin)->post(route('admin.bookings.technicians.store'), validTechnicianPayload([
        'user_id' => $otherUser->id,
    ]));

    $response->assertRedirect();
    $technician = Technician::where('name', 'Jamie Fitter')->sole();
    expect($technician->user_id)->toBeNull();
});

test('a user without bookings.manage is forbidden from viewing, creating, or updating technicians', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->assignRole('ecommerce');
    $technician = Technician::factory()->create();

    $this->actingAs($viewer)->get(route('admin.bookings.technicians.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('admin.bookings.technicians.store'), validTechnicianPayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('admin.bookings.technicians.update', $technician), validTechnicianPayload())->assertForbidden();
});
