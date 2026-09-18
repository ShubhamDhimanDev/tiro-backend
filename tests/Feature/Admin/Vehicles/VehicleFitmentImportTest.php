<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

const VEHICLE_FITMENT_CSV_HEADER = 'make,model,series,body_type,year_from,year_to,position,width,profile,rim_diameter,load_index,speed_rating,is_staggered,source,confidence,notes';

test('a super_admin can bulk-import a valid CSV of non-staggered fitment rows', function () {
    $admin = actingSuperAdmin();

    $csv = VEHICLE_FITMENT_CSV_HEADER."\n".
        'Toyota,Corolla,Ascent Sport,sedan,2019,2023,all,205,55,16,91,V,false,manual,confirmed,';

    $file = UploadedFile::fake()->createWithContent('fitments.csv', $csv);

    $response = $this->actingAs($admin)->post(route('admin.vehicles.import.store'), [
        'file' => $file,
        'dry_run' => false,
    ]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('result.rowsProcessed', 1)
        ->where('result.vehiclesCreated', 1)
        ->where('result.fitmentsCreated', 1)
        ->where('result.errors', [])
    );

    $this->assertDatabaseHas('vehicles', ['make' => 'Toyota', 'model' => 'Corolla']);
});

test('a row with an unrecognized enum value is reported per-row without aborting the rest of the file', function () {
    $admin = actingSuperAdmin();

    $csv = VEHICLE_FITMENT_CSV_HEADER."\n".
        'Toyota,Corolla,Ascent Sport,sedan,2019,2023,sideways,205,55,16,91,V,false,manual,confirmed,'."\n".
        'Mazda,CX-5,Touring,suv,2020,2024,all,225,60,17,99,H,false,manual,confirmed,';

    $file = UploadedFile::fake()->createWithContent('fitments.csv', $csv);

    $response = $this->actingAs($admin)->post(route('admin.vehicles.import.store'), [
        'file' => $file,
        'dry_run' => false,
    ]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('result.vehiclesCreated', 1)
        ->where('result.fitmentsCreated', 1)
        ->has('result.errors', 1)
        ->where('result.errors.0.row', 1)
    );

    $this->assertDatabaseHas('vehicles', ['make' => 'Mazda', 'model' => 'CX-5']);
    $this->assertDatabaseMissing('vehicles', ['make' => 'Toyota', 'model' => 'Corolla']);
});

test('a dry-run import reports the outcome without persisting anything', function () {
    $admin = actingSuperAdmin();

    $csv = VEHICLE_FITMENT_CSV_HEADER."\n".
        'Toyota,Corolla,Ascent Sport,sedan,2019,2023,all,205,55,16,91,V,false,manual,confirmed,';

    $file = UploadedFile::fake()->createWithContent('fitments.csv', $csv);

    $response = $this->actingAs($admin)->post(route('admin.vehicles.import.store'), [
        'file' => $file,
        'dry_run' => true,
    ]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('result.dryRun', true)
        ->where('result.vehiclesCreated', 1)
    );

    $this->assertDatabaseMissing('vehicles', ['make' => 'Toyota', 'model' => 'Corolla']);
});

test('a JSON fitment file imports the same as an equivalent CSV', function () {
    $admin = actingSuperAdmin();

    $json = json_encode([
        [
            'make' => 'Hyundai',
            'model' => 'i30',
            'series' => null,
            'body_type' => 'hatch',
            'year_from' => 2018,
            'year_to' => 2022,
            'position' => 'all',
            'width' => 205,
            'profile' => 55,
            'rim_diameter' => 16,
            'load_index' => '91',
            'speed_rating' => 'V',
            'is_staggered' => false,
            'source' => 'manual',
            'confidence' => 'confirmed',
            'notes' => null,
        ],
    ]);

    $file = UploadedFile::fake()->createWithContent('fitments.json', (string) $json);

    $response = $this->actingAs($admin)->post(route('admin.vehicles.import.store'), [
        'file' => $file,
        'dry_run' => false,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('vehicles', ['make' => 'Hyundai', 'model' => 'i30']);
});

test('a user with only vehicles.view cannot reach the import screen', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('vehicles.view');

    $csv = VEHICLE_FITMENT_CSV_HEADER."\n".
        'Toyota,Corolla,Ascent Sport,sedan,2019,2023,all,205,55,16,91,V,false,manual,confirmed,';
    $file = UploadedFile::fake()->createWithContent('fitments.csv', $csv);

    $this->actingAs($viewer)->get(route('admin.vehicles.import.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('admin.vehicles.import.store'), [
        'file' => $file,
        'dry_run' => false,
    ])->assertForbidden();

    $this->assertDatabaseMissing('vehicles', ['make' => 'Toyota', 'model' => 'Corolla']);
});

test('operations (vehicles.manage) can reach the import screen', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('operations');

    $this->actingAs($user)->get(route('admin.vehicles.import.index'))->assertOk();
});
