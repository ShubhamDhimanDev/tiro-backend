<?php

use App\Enums\Status;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\Van;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Covers App\Http\Controllers\Admin\Bookings\VanController and
 * App\Http\Requests\Admin\Bookings\VanRequest. Roster CRUD for the
 * daily job-cap unit the booking engine schedules against — see
 * VanRequest's docblock.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function fleetManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('operations');

    return $user;
}

function validVanPayload(array $overrides = []): array
{
    return array_merge([
        'rego' => 'ABC123',
        'name' => 'Van 1',
        'home_stock_location_id' => StockLocation::factory()->create()->id,
        'has_alignment_equipment' => false,
        'max_jobs_per_day' => 8,
        'status' => Status::Active->value,
    ], $overrides);
}

test('a bookings.manage user can create a van', function () {
    $admin = fleetManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.vans.store'), validVanPayload([
        'rego' => 'XYZ789',
        'name' => 'Van 2',
    ]));

    $response->assertRedirect();
    $this->assertDatabaseHas('vans', ['rego' => 'XYZ789', 'name' => 'Van 2']);
});

test('a bookings.manage user can update a van', function () {
    $admin = fleetManager();
    $van = Van::factory()->create(['name' => 'Old Van Name']);

    $response = $this->actingAs($admin)->put(route('admin.bookings.vans.update', $van), validVanPayload([
        'rego' => $van->rego,
        'name' => 'New Van Name',
        'home_stock_location_id' => $van->home_stock_location_id,
    ]));

    $response->assertRedirect();
    expect($van->refresh()->name)->toBe('New Van Name');
});

test('a duplicate rego is rejected', function () {
    $admin = fleetManager();
    Van::factory()->create(['rego' => 'TAKEN01']);

    $response = $this->actingAs($admin)->post(route('admin.bookings.vans.store'), validVanPayload([
        'rego' => 'TAKEN01',
        'name' => 'Another Van',
    ]));

    $response->assertSessionHasErrors('rego');
});

test('updating a van without changing its own rego does not trip the uniqueness rule against itself', function () {
    $admin = fleetManager();
    $van = Van::factory()->create(['rego' => 'KEEP001']);

    $response = $this->actingAs($admin)->put(route('admin.bookings.vans.update', $van), validVanPayload([
        'rego' => 'KEEP001',
        'name' => 'Renamed Van',
        'home_stock_location_id' => $van->home_stock_location_id,
    ]));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors('rego');
});

test('max_jobs_per_day must be between 1 and 255', function () {
    $admin = fleetManager();

    $this->actingAs($admin)->post(route('admin.bookings.vans.store'), validVanPayload(['max_jobs_per_day' => 0]))
        ->assertSessionHasErrors('max_jobs_per_day');

    $this->actingAs($admin)->post(route('admin.bookings.vans.store'), validVanPayload(['max_jobs_per_day' => 256]))
        ->assertSessionHasErrors('max_jobs_per_day');
});

test('home_stock_location_id must reference an existing stock location', function () {
    $admin = fleetManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.vans.store'), validVanPayload([
        'home_stock_location_id' => 999999,
    ]));

    $response->assertSessionHasErrors('home_stock_location_id');
});

test('a user without bookings.manage is forbidden from viewing, creating, or updating vans', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->assignRole('ecommerce');
    $van = Van::factory()->create();

    $this->actingAs($viewer)->get(route('admin.bookings.vans.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('admin.bookings.vans.store'), validVanPayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('admin.bookings.vans.update', $van), validVanPayload())->assertForbidden();
});
