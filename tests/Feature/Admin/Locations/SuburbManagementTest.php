<?php

use App\Models\ServiceZone;
use App\Models\State;
use App\Models\Suburb;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('lat and lng are required to create a suburb', function () {
    $admin = actingSuperAdmin();
    $state = State::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.locations.suburbs.store'), [
        'name' => 'Richmond',
        'state_id' => $state->id,
        'postcode' => '3121',
        'lat' => null,
        'lng' => null,
    ]);

    $response->assertSessionHasErrors(['lat', 'lng']);
    $this->assertDatabaseMissing('suburbs', ['name' => 'Richmond']);
});

test('a super_admin can create, update, and delete a suburb', function () {
    $admin = actingSuperAdmin();
    $state = State::factory()->create();

    $store = $this->actingAs($admin)->post(route('admin.locations.suburbs.store'), [
        'name' => 'Richmond',
        'state_id' => $state->id,
        'postcode' => '3121',
        'lat' => -37.8230,
        'lng' => 144.9986,
    ]);
    $store->assertRedirect();
    $suburb = Suburb::where('name', 'Richmond')->sole();

    $update = $this->actingAs($admin)->put(route('admin.locations.suburbs.update', $suburb), [
        'name' => 'Richmond',
        'state_id' => $state->id,
        'postcode' => '3122',
        'lat' => $suburb->lat,
        'lng' => $suburb->lng,
    ]);
    $update->assertRedirect();
    expect($suburb->refresh()->postcode)->toBe('3122');

    $destroy = $this->actingAs($admin)->delete(route('admin.locations.suburbs.destroy', $suburb));
    $destroy->assertRedirect();
    $this->assertDatabaseMissing('suburbs', ['id' => $suburb->id]);
});

test('deleting a suburb cascades its zone membership', function () {
    $admin = actingSuperAdmin();
    $zone = ServiceZone::factory()->suburbList()->create();
    $suburb = Suburb::factory()->create(['state_id' => $zone->state_id]);
    $zone->suburbs()->attach($suburb->id);

    $this->actingAs($admin)->delete(route('admin.locations.suburbs.destroy', $suburb))->assertRedirect();

    $this->assertDatabaseMissing('service_zone_suburb', ['suburb_id' => $suburb->id]);
});

test('a duplicate name+state+postcode combination is rejected', function () {
    $admin = actingSuperAdmin();
    $existing = Suburb::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.locations.suburbs.store'), [
        'name' => $existing->name,
        'state_id' => $existing->state_id,
        'postcode' => $existing->postcode,
        'lat' => $existing->lat,
        'lng' => $existing->lng,
    ]);

    $response->assertSessionHasErrors('postcode');
});

test('a user with only locations.view cannot create or delete a suburb', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('locations.view');
    $state = State::factory()->create();
    $suburb = Suburb::factory()->create();

    $this->actingAs($viewer)->post(route('admin.locations.suburbs.store'), [
        'name' => 'Should Fail',
        'state_id' => $state->id,
        'postcode' => '1234',
        'lat' => 0,
        'lng' => 0,
    ])->assertForbidden();

    $this->actingAs($viewer)->delete(route('admin.locations.suburbs.destroy', $suburb))->assertForbidden();
});
