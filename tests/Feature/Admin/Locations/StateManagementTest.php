<?php

use App\Enums\Status;
use App\Models\State;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a super_admin can create a state, which starts inactive regardless of input', function () {
    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.locations.states.store'), [
        'code' => 'nsw',
        'name' => 'New South Wales',
        'status' => Status::Active->value,
        'is_active' => true,
    ]);

    $response->assertRedirect();

    $state = State::where('code', 'NSW')->sole();
    expect($state->is_active)->toBeFalse();
});

test('the general update form cannot flip is_active', function () {
    $admin = actingSuperAdmin();
    $state = State::factory()->create(['is_active' => false]);

    $this->actingAs($admin)->put(route('admin.locations.states.update', $state), [
        'code' => $state->code,
        'name' => $state->name,
        'status' => Status::Active->value,
        'is_active' => true,
    ]);

    expect($state->refresh()->is_active)->toBeFalse();
});

test('toggle-active is a dedicated, explicit action', function () {
    $admin = actingSuperAdmin();
    $state = State::factory()->create(['is_active' => false]);

    $activate = $this->actingAs($admin)->patch(route('admin.locations.states.toggle-active', $state), [
        'is_active' => true,
    ]);
    $activate->assertRedirect();
    expect($state->refresh()->is_active)->toBeTrue();

    $deactivate = $this->actingAs($admin)->patch(route('admin.locations.states.toggle-active', $state), [
        'is_active' => false,
    ]);
    $deactivate->assertRedirect();
    expect($state->refresh()->is_active)->toBeFalse();
});

test('a duplicate state code is rejected', function () {
    $admin = actingSuperAdmin();
    State::factory()->create(['code' => 'VIC']);

    $response = $this->actingAs($admin)->post(route('admin.locations.states.store'), [
        'code' => 'vic',
        'name' => 'Victoria Duplicate',
        'status' => Status::Active->value,
    ]);

    $response->assertSessionHasErrors('code');
});

test('a user with only locations.view cannot create or toggle a state', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('locations.view');
    $state = State::factory()->create();

    $this->actingAs($viewer)->post(route('admin.locations.states.store'), [
        'code' => 'QLD',
        'name' => 'Queensland',
        'status' => Status::Active->value,
    ])->assertForbidden();

    $this->actingAs($viewer)->patch(route('admin.locations.states.toggle-active', $state), [
        'is_active' => true,
    ])->assertForbidden();
});
