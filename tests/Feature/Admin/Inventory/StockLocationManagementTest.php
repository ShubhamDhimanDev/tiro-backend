<?php

use App\Enums\ServiceZoneType;
use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\State;
use App\Models\StockLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a super_admin can create, update, and delete a stock location', function () {
    $admin = actingSuperAdmin();

    $store = $this->actingAs($admin)->post(route('admin.inventory.locations.store'), [
        'name' => 'Melbourne Depot',
        'address' => '1 Example St, Melbourne VIC 3000',
        'lat' => -37.8136,
        'lng' => 144.9631,
    ]);
    $store->assertRedirect();
    $location = StockLocation::where('name', 'Melbourne Depot')->sole();

    $update = $this->actingAs($admin)->put(route('admin.inventory.locations.update', $location), [
        'name' => 'Melbourne Depot (Renamed)',
        'address' => $location->address,
        'lat' => $location->lat,
        'lng' => $location->lng,
    ]);
    $update->assertRedirect();
    expect($location->refresh()->name)->toBe('Melbourne Depot (Renamed)');

    $destroy = $this->actingAs($admin)->delete(route('admin.inventory.locations.destroy', $location));
    $destroy->assertRedirect();
    $this->assertDatabaseMissing('stock_locations', ['id' => $location->id]);
});

test('a super_admin can link and unlink a stock location to a service zone', function () {
    $admin = actingSuperAdmin();
    $state = State::factory()->create();
    $zone = ServiceZone::factory()->create(['state_id' => $state->id, 'type' => ServiceZoneType::Radius, 'status' => Status::Active]);
    $location = StockLocation::factory()->create();

    $link = $this->actingAs($admin)->post(route('admin.inventory.locations.zones.store', $location), [
        'service_zone_id' => $zone->id,
    ]);
    $link->assertRedirect();
    expect($location->serviceZones()->pluck('service_zones.id')->all())->toBe([$zone->id]);

    $unlink = $this->actingAs($admin)->delete(route('admin.inventory.locations.zones.destroy', [$location, $zone]));
    $unlink->assertRedirect();
    expect($location->serviceZones()->count())->toBe(0);
});

test('a user with only inventory.view cannot create a stock location', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('inventory.view');

    $this->actingAs($viewer)->post(route('admin.inventory.locations.store'), [
        'name' => 'Should Fail',
        'address' => 'Nowhere',
        'lat' => 0,
        'lng' => 0,
    ])->assertForbidden();
});
