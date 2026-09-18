<?php

use App\Models\InventoryItem;
use App\Models\StockLocation;
use App\Models\TyreVariant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a super_admin can add, update, and remove a stock row at a location', function () {
    $admin = actingSuperAdmin();
    $location = StockLocation::factory()->create();
    $variant = TyreVariant::factory()->create();

    $store = $this->actingAs($admin)->post(route('admin.inventory.locations.items.store', $location), [
        'tyre_variant_id' => $variant->id,
        'qty_on_hand' => 10,
        'qty_reserved' => 2,
        'reorder_point' => 3,
    ]);
    $store->assertRedirect();
    $item = InventoryItem::where('stock_location_id', $location->id)->sole();

    $update = $this->actingAs($admin)->put(route('admin.inventory.items.update', $item), [
        'tyre_variant_id' => $variant->id,
        'qty_on_hand' => 20,
        'qty_reserved' => 5,
        'reorder_point' => 4,
    ]);
    $update->assertRedirect();
    expect($item->refresh()->qty_on_hand)->toBe(20);

    $destroy = $this->actingAs($admin)->delete(route('admin.inventory.items.destroy', $item));
    $destroy->assertRedirect();
    $this->assertDatabaseMissing('inventory_items', ['id' => $item->id]);
});

test('the same variant cannot be stocked twice at the same location', function () {
    $admin = actingSuperAdmin();
    $location = StockLocation::factory()->create();
    $variant = TyreVariant::factory()->create();
    InventoryItem::factory()->create(['stock_location_id' => $location->id, 'tyre_variant_id' => $variant->id]);

    $response = $this->actingAs($admin)->post(route('admin.inventory.locations.items.store', $location), [
        'tyre_variant_id' => $variant->id,
        'qty_on_hand' => 5,
        'qty_reserved' => 0,
        'reorder_point' => 1,
    ]);

    $response->assertSessionHasErrors('tyre_variant_id');
});

test('qty_reserved cannot exceed qty_on_hand', function () {
    $admin = actingSuperAdmin();
    $location = StockLocation::factory()->create();
    $variant = TyreVariant::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.inventory.locations.items.store', $location), [
        'tyre_variant_id' => $variant->id,
        'qty_on_hand' => 5,
        'qty_reserved' => 10,
        'reorder_point' => 1,
    ]);

    $response->assertSessionHasErrors('qty_reserved');
});

test('a user with only inventory.view cannot modify stock rows', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo('inventory.view');
    $location = StockLocation::factory()->create();
    $variant = TyreVariant::factory()->create();

    $this->actingAs($viewer)->post(route('admin.inventory.locations.items.store', $location), [
        'tyre_variant_id' => $variant->id,
        'qty_on_hand' => 5,
        'qty_reserved' => 0,
        'reorder_point' => 1,
    ])->assertForbidden();
});
