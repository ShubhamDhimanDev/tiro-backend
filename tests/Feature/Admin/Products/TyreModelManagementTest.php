<?php

use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Enums\TyreConstruction;
use App\Enums\TyreType;
use App\Models\Brand;
use App\Models\TyreModel;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a super_admin can create a tyre model under a brand, including service_inclusions and released_at', function () {
    $admin = actingSuperAdmin();
    $brand = Brand::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.products.brands.models.store', $brand), [
        'name' => 'Turanza T005',
        'slug' => 'bridgestone-turanza-t005',
        'category' => TyreCategory::Car->value,
        'tyre_type' => TyreType::Highway->value,
        'construction' => TyreConstruction::Radial->value,
        'run_flat' => false,
        'description' => 'A great tyre.',
        'warranty_text' => null,
        'warranty_km' => 80000,
        'service_inclusions' => ['Fitting', 'Balancing'],
        'released_at' => null,
        'images' => [],
        'status' => Status::Active->value,
    ]);

    $response->assertRedirect();

    $tyreModel = TyreModel::where('slug', 'bridgestone-turanza-t005')->sole();
    expect($tyreModel->brand_id)->toBe($brand->id)
        ->and($tyreModel->service_inclusions)->toBe(['Fitting', 'Balancing'])
        ->and($tyreModel->released_at)->toBeNull();
});

test('leaving released_at blank is allowed (falls back to created_at for sorting)', function () {
    $admin = actingSuperAdmin();
    $tyreModel = TyreModel::factory()->create(['released_at' => now()]);

    $response = $this->actingAs($admin)->put(route('admin.products.models.update', $tyreModel), [
        'name' => $tyreModel->name,
        'slug' => $tyreModel->slug,
        'category' => $tyreModel->category->value,
        'tyre_type' => $tyreModel->tyre_type->value,
        'construction' => $tyreModel->construction->value,
        'run_flat' => $tyreModel->run_flat,
        'service_inclusions' => [],
        'released_at' => null,
        'images' => [],
        'status' => Status::Active->value,
    ]);

    $response->assertRedirect();
    expect($tyreModel->refresh()->released_at)->toBeNull();
});

test('a user with only products.view cannot create a tyre model', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('products.view');
    $brand = Brand::factory()->create();

    $this->actingAs($viewer)->post(route('admin.products.brands.models.store', $brand), [
        'name' => 'Should Fail',
        'slug' => 'should-fail',
        'category' => TyreCategory::Car->value,
        'tyre_type' => TyreType::Highway->value,
        'construction' => TyreConstruction::Radial->value,
        'run_flat' => false,
        'status' => Status::Active->value,
    ])->assertForbidden();
});
