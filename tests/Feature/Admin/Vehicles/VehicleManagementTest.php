<?php

use App\Enums\Status;
use App\Enums\VehicleFitmentSource;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFitment;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validVehiclePayload(array $overrides = []): array
{
    return array_merge([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'series' => 'Ascent Sport',
        'body_type' => 'sedan',
        'year_from' => 2019,
        'year_to' => 2023,
        'slug' => null,
        'status' => Status::Active->value,
        'is_staggered' => false,
        'fitments' => [
            [
                'position' => 'all',
                'width' => 205,
                'profile' => 55,
                'rim_diameter' => 16,
                'load_index' => '91',
                'speed_rating' => 'V',
                'confidence' => 'confirmed',
                'notes' => null,
            ],
        ],
    ], $overrides);
}

/**
 * @return array<int, array<string, mixed>>
 */
function staggeredFitmentRows(): array
{
    return [
        [
            'position' => 'front',
            'width' => 225,
            'profile' => 40,
            'rim_diameter' => 18,
            'load_index' => '92',
            'speed_rating' => 'W',
            'confidence' => 'confirmed',
            'notes' => null,
        ],
        [
            'position' => 'rear',
            'width' => 245,
            'profile' => 35,
            'rim_diameter' => 18,
            'load_index' => '93',
            'speed_rating' => 'W',
            'confidence' => 'likely',
            'notes' => 'Wider rear per manufacturer guide',
        ],
    ];
}

test('a super_admin can create a non-staggered vehicle with a single all fitment row', function () {
    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.vehicles.store'), validVehiclePayload());

    $response->assertRedirect();
    $this->assertDatabaseHas('vehicles', ['make' => 'Toyota', 'model' => 'Corolla']);

    $vehicle = Vehicle::query()->where('make', 'Toyota')->where('model', 'Corolla')->firstOrFail();
    expect($vehicle->fitments)->toHaveCount(1);
    expect($vehicle->fitments->first()->position->value)->toBe('all');
    expect($vehicle->fitments->first()->source)->toBe(VehicleFitmentSource::Manual);
    expect($vehicle->slug)->not->toBeEmpty();
});

test('a super_admin can create a staggered vehicle with front and rear fitment rows', function () {
    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.vehicles.store'), validVehiclePayload([
        'make' => 'BMW',
        'model' => '3 Series',
        'is_staggered' => true,
        'fitments' => staggeredFitmentRows(),
    ]));

    $response->assertRedirect();
    $vehicle = Vehicle::query()->where('make', 'BMW')->firstOrFail();
    expect($vehicle->fitments)->toHaveCount(2);
    expect($vehicle->fitments->pluck('position.value')->sort()->values()->all())->toBe(['front', 'rear']);
    expect($vehicle->fitments->every(fn (VehicleFitment $f) => $f->is_staggered === true))->toBeTrue();
});

test('a non-staggered vehicle submitted with front/rear rows is rejected by the fitment set validator', function () {
    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.vehicles.store'), validVehiclePayload([
        'is_staggered' => false,
        'fitments' => staggeredFitmentRows(),
    ]));

    $response->assertSessionHasErrors('fitments');
    $this->assertDatabaseMissing('vehicles', ['make' => 'Toyota', 'model' => 'Corolla']);
});

test('a staggered vehicle submitted with only one fitment row is rejected by the fitment set validator', function () {
    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.vehicles.store'), validVehiclePayload([
        'is_staggered' => true,
        'fitments' => [staggeredFitmentRows()[0]],
    ]));

    $response->assertSessionHasErrors('fitments');
});

test('a duplicate make/model/series/body_type/year range is rejected', function () {
    $admin = actingSuperAdmin();
    $this->actingAs($admin)->post(route('admin.vehicles.store'), validVehiclePayload())->assertRedirect();

    $response = $this->actingAs($admin)->post(route('admin.vehicles.store'), validVehiclePayload());

    $response->assertSessionHasErrors('make');
    expect(Vehicle::query()->where('make', 'Toyota')->count())->toBe(1);
});

test('source is never accepted from the request and is always persisted as manual', function () {
    $admin = actingSuperAdmin();

    $payload = validVehiclePayload();
    $payload['fitments'][0]['source'] = VehicleFitmentSource::VendorFeed->value;

    $response = $this->actingAs($admin)->post(route('admin.vehicles.store'), $payload);

    $response->assertRedirect();
    $vehicle = Vehicle::query()->where('make', 'Toyota')->firstOrFail();
    expect($vehicle->fitments->first()->source)->toBe(VehicleFitmentSource::Manual);
});

test('editing a vehicle from non-staggered to staggered replaces its fitment rows', function () {
    $admin = actingSuperAdmin();
    $vehicle = Vehicle::factory()->create(['make' => 'Mazda', 'model' => 'CX-5']);
    VehicleFitment::factory()->create(['vehicle_id' => $vehicle->id]);

    $response = $this->actingAs($admin)->put(route('admin.vehicles.update', $vehicle), validVehiclePayload([
        'make' => 'Mazda',
        'model' => 'CX-5',
        'series' => $vehicle->series,
        'body_type' => $vehicle->body_type,
        'year_from' => $vehicle->year_from,
        'year_to' => $vehicle->year_to,
        'slug' => $vehicle->slug,
        'is_staggered' => true,
        'fitments' => staggeredFitmentRows(),
    ]));

    $response->assertRedirect();
    $vehicle->refresh();
    expect($vehicle->fitments)->toHaveCount(2);
    expect($vehicle->fitments->pluck('position.value')->sort()->values()->all())->toBe(['front', 'rear']);
});

test('deleting a vehicle cascades to its fitment rows', function () {
    $admin = actingSuperAdmin();
    $vehicle = Vehicle::factory()->create();
    VehicleFitment::factory()->create(['vehicle_id' => $vehicle->id]);

    $response = $this->actingAs($admin)->delete(route('admin.vehicles.destroy', $vehicle));

    $response->assertRedirect();
    $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
    $this->assertDatabaseMissing('vehicle_fitments', ['vehicle_id' => $vehicle->id]);
});

test('a user with only vehicles.view cannot create, update, or delete a vehicle', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('vehicles.view');
    $vehicle = Vehicle::factory()->create();

    $this->actingAs($viewer)->post(route('admin.vehicles.store'), validVehiclePayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('admin.vehicles.update', $vehicle), validVehiclePayload())->assertForbidden();
    $this->actingAs($viewer)->delete(route('admin.vehicles.destroy', $vehicle))->assertForbidden();
});

test('the vehicles.manage tier (operations) can manage while the vehicles.view tier (ecommerce, customer_support) cannot', function (string $role, bool $canManage) {
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    $response = $this->actingAs($user)->get(route('admin.vehicles.index'));
    $response->assertOk();

    $storeResponse = $this->actingAs($user)->post(route('admin.vehicles.store'), validVehiclePayload([
        'make' => 'Unique-'.$role,
    ]));

    $canManage ? $storeResponse->assertRedirect() : $storeResponse->assertForbidden();
})->with([
    'operations can manage' => ['operations', true],
    'ecommerce can only view' => ['ecommerce', false],
    'customer_support can only view' => ['customer_support', false],
]);

test('fleet has no access to the vehicles module at all', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('fleet');

    $this->actingAs($user)->get(route('admin.vehicles.index'))->assertForbidden();
});
