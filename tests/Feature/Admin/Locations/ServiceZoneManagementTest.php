<?php

use App\Enums\ServiceZoneType;
use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\State;
use App\Models\Suburb;
use App\Models\User;
use Database\Factories\ServiceZoneFactory;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function validZonePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Melbourne Metro',
        'state_id' => State::factory()->create()->id,
        'type' => ServiceZoneType::Radius->value,
        'origin_lat' => -37.8136,
        'origin_lng' => 144.9631,
        'radius_km' => 25,
        'operating_hours' => ServiceZoneFactory::defaultOperatingHours(),
        'priority' => 0,
        'status' => Status::Active->value,
    ], $overrides);
}

test('a radius zone requires origin_lat, origin_lng, and radius_km', function () {
    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.locations.zones.store'), validZonePayload([
        'origin_lat' => null,
        'origin_lng' => null,
        'radius_km' => null,
    ]));

    $response->assertSessionHasErrors(['origin_lat', 'origin_lng', 'radius_km']);
});

test('a suburb_list zone does not require origin_lat, origin_lng, or radius_km', function () {
    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.locations.zones.store'), validZonePayload([
        'type' => ServiceZoneType::SuburbList->value,
        'origin_lat' => null,
        'origin_lng' => null,
        'radius_km' => null,
    ]));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors(['origin_lat', 'origin_lng', 'radius_km']);
});

test('operating_hours rejects a day with only an open time and no close time', function () {
    $admin = actingSuperAdmin();
    $hours = ServiceZoneFactory::defaultOperatingHours();
    $hours['mon'] = ['open' => '08:00'];

    $response = $this->actingAs($admin)->post(route('admin.locations.zones.store'), validZonePayload([
        'operating_hours' => $hours,
    ]));

    $response->assertSessionHasErrors('operating_hours.mon');
});

test('operating_hours rejects a closing time before the opening time', function () {
    $admin = actingSuperAdmin();
    $hours = ServiceZoneFactory::defaultOperatingHours();
    $hours['mon'] = ['open' => '18:00', 'close' => '08:00'];

    $response = $this->actingAs($admin)->post(route('admin.locations.zones.store'), validZonePayload([
        'operating_hours' => $hours,
    ]));

    $response->assertSessionHasErrors('operating_hours.mon.close');
});

test('operating_hours accepts a closed day as null', function () {
    $admin = actingSuperAdmin();
    $hours = ServiceZoneFactory::defaultOperatingHours();
    $hours['sun'] = null;

    $response = $this->actingAs($admin)->post(route('admin.locations.zones.store'), validZonePayload([
        'operating_hours' => $hours,
    ]));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors('operating_hours');
});

test('assigning a suburb already in another active suburb_list zone succeeds with a warning, not a block', function () {
    $admin = actingSuperAdmin();
    $state = State::factory()->create();
    $suburb = Suburb::factory()->create(['state_id' => $state->id]);

    $zoneA = ServiceZone::factory()->suburbList()->create(['state_id' => $state->id, 'name' => 'Zone A']);
    $zoneB = ServiceZone::factory()->suburbList()->create(['state_id' => $state->id, 'name' => 'Zone B']);
    $zoneA->suburbs()->attach($suburb->id);

    $response = $this->actingAs($admin)->post(
        route('admin.locations.zones.suburbs.store', $zoneB),
        ['suburb_id' => $suburb->id],
    );

    $response->assertRedirect();
    expect($zoneB->suburbs()->pluck('suburbs.id')->all())->toBe([$suburb->id]);
    $response->assertInertiaFlash('toast.type', 'warning');
});

test('assigning a suburb with no other active zone membership succeeds with a success toast', function () {
    $admin = actingSuperAdmin();
    $state = State::factory()->create();
    $suburb = Suburb::factory()->create(['state_id' => $state->id]);
    $zone = ServiceZone::factory()->suburbList()->create(['state_id' => $state->id]);

    $response = $this->actingAs($admin)->post(
        route('admin.locations.zones.suburbs.store', $zone),
        ['suburb_id' => $suburb->id],
    );

    $response->assertRedirect();
    $response->assertInertiaFlash('toast.type', 'success');
});

test('a user with only locations.view cannot create a zone or assign a suburb', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('locations.view');
    $zone = ServiceZone::factory()->suburbList()->create();
    $suburb = Suburb::factory()->create(['state_id' => $zone->state_id]);

    $this->actingAs($viewer)->post(route('admin.locations.zones.store'), validZonePayload())
        ->assertForbidden();

    $this->actingAs($viewer)->post(route('admin.locations.zones.suburbs.store', $zone), [
        'suburb_id' => $suburb->id,
    ])->assertForbidden();
});
