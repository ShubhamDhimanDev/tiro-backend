<?php

use App\Enums\Status;
use App\Enums\TyreSidewall;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('leaving slug blank on create auto-generates it from the model and size', function () {
    $admin = actingSuperAdmin();
    $tyreModel = TyreModel::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.products.models.variants.store', $tyreModel), [
        'sku' => 'SKU-001',
        'slug' => null,
        'width' => 205,
        'profile' => 55,
        'rim_diameter' => 16,
        'load_index' => '91',
        'speed_rating' => 'V',
        'sidewall' => TyreSidewall::Standard->value,
        'base_price' => 18900,
        'status' => Status::Active->value,
    ]);

    $response->assertRedirect();

    $variant = TyreVariant::where('sku', 'SKU-001')->sole();
    expect($variant->slug)->toBe("{$tyreModel->slug}-205-55-r16");
});

test('slug is required when editing an existing variant', function () {
    $admin = actingSuperAdmin();
    $variant = TyreVariant::factory()->create();

    $response = $this->actingAs($admin)->put(route('admin.products.variants.update', $variant), [
        'sku' => $variant->sku,
        'slug' => '',
        'width' => $variant->width,
        'profile' => $variant->profile,
        'rim_diameter' => $variant->rim_diameter,
        'load_index' => $variant->load_index,
        'speed_rating' => $variant->speed_rating,
        'sidewall' => $variant->sidewall->value,
        'base_price' => $variant->base_price,
        'status' => Status::Active->value,
    ]);

    $response->assertSessionHasErrors('slug');
});

test('an admin can edit an existing variant slug to a custom value', function () {
    $admin = actingSuperAdmin();
    $variant = TyreVariant::factory()->create();

    $response = $this->actingAs($admin)->put(route('admin.products.variants.update', $variant), [
        'sku' => $variant->sku,
        'slug' => 'custom-slug-override',
        'width' => $variant->width,
        'profile' => $variant->profile,
        'rim_diameter' => $variant->rim_diameter,
        'load_index' => $variant->load_index,
        'speed_rating' => $variant->speed_rating,
        'sidewall' => $variant->sidewall->value,
        'base_price' => $variant->base_price,
        'status' => Status::Active->value,
    ]);

    $response->assertRedirect();
    expect($variant->refresh()->slug)->toBe('custom-slug-override');
});

test('creating a duplicate exact spec under the same model is rejected', function () {
    $admin = actingSuperAdmin();
    $tyreModel = TyreModel::factory()->create();
    $existing = TyreVariant::factory()->create([
        'tyre_model_id' => $tyreModel->id,
        'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '91', 'speed_rating' => 'V',
    ]);

    $response = $this->actingAs($admin)->post(route('admin.products.models.variants.store', $tyreModel), [
        'sku' => 'SKU-DUP',
        'width' => 205,
        'profile' => 55,
        'rim_diameter' => 16,
        'load_index' => '91',
        'speed_rating' => 'V',
        'sidewall' => $existing->sidewall->value,
        'base_price' => 10000,
        'status' => Status::Active->value,
    ]);

    $response->assertSessionHasErrors('width');
});
