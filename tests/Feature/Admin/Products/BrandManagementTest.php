<?php

use App\Enums\Status;
use App\Models\Brand;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a super_admin can create a brand', function () {
    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.products.brands.store'), [
        'name' => 'Bridgestone',
        'slug' => 'bridgestone',
        'logo_path' => null,
        'country_of_origin' => 'Japan',
        'status' => Status::Active->value,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('brands', ['slug' => 'bridgestone', 'name' => 'Bridgestone']);
});

test('a super_admin can update a brand', function () {
    $admin = actingSuperAdmin();
    $brand = Brand::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($admin)->put(route('admin.products.brands.update', $brand), [
        'name' => 'New Name',
        'slug' => $brand->slug,
        'logo_path' => null,
        'country_of_origin' => $brand->country_of_origin,
        'status' => Status::Active->value,
    ]);

    $response->assertRedirect();
    expect($brand->refresh()->name)->toBe('New Name');
});

test('a duplicate slug is rejected', function () {
    $admin = actingSuperAdmin();
    Brand::factory()->create(['slug' => 'taken-slug']);

    $response = $this->actingAs($admin)->post(route('admin.products.brands.store'), [
        'name' => 'Another Brand',
        'slug' => 'taken-slug',
        'status' => Status::Active->value,
    ]);

    $response->assertSessionHasErrors('slug');
});

test('a user with only products.view cannot create or update a brand', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('products.view');
    $brand = Brand::factory()->create();

    $this->actingAs($viewer)->post(route('admin.products.brands.store'), [
        'name' => 'Should Fail',
        'slug' => 'should-fail',
        'status' => Status::Active->value,
    ])->assertForbidden();

    $this->actingAs($viewer)->put(route('admin.products.brands.update', $brand), [
        'name' => 'Should Fail',
        'slug' => $brand->slug,
        'status' => Status::Active->value,
    ])->assertForbidden();
});

test('a user with products.view can see the brand index', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('products.view');
    Brand::factory()->count(2)->create();

    $this->actingAs($viewer)->get(route('admin.products.brands.index'))->assertOk();
});

test('a user without products.view is denied the brand index', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('admin.products.brands.index'))->assertForbidden();
});
