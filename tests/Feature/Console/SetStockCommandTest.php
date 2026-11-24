<?php

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\StockLocation;
use App\Models\TyreVariant;

/**
 * `php artisan inventory:set-stock {quantity=20} [--location=*] [--all-locations] [--dry-run] [--force]`.
 */
it('sets stock to 20 for every variant, creating missing rows and updating existing ones', function () {
    $location = StockLocation::factory()->create();
    $variants = TyreVariant::factory()->count(3)->create();

    InventoryItem::factory()->create([
        'tyre_variant_id' => $variants[0]->id, 'stock_location_id' => $location->id,
        'qty_on_hand' => 5, 'qty_reserved' => 2, 'reorder_point' => 4,
    ]);

    $this->artisan('inventory:set-stock', ['--force' => true])->assertSuccessful();

    expect(InventoryItem::query()->count())->toBe(3)
        ->and(InventoryItem::query()->pluck('qty_on_hand')->unique()->all())->toBe([20]);

    $existing = InventoryItem::query()->where('tyre_variant_id', $variants[0]->id)->sole();

    // Reserved units and the reorder point are left alone.
    expect($existing->qty_reserved)->toBe(2)->and($existing->reorder_point)->toBe(4);

    expect(AuditLog::query()->where('action', 'inventory.bulk_set_stock')->count())->toBe(1);
});

it('accepts another quantity', function () {
    StockLocation::factory()->create();
    TyreVariant::factory()->create();

    $this->artisan('inventory:set-stock', ['quantity' => '7', '--force' => true])->assertSuccessful();

    expect(InventoryItem::query()->sole()->qty_on_hand)->toBe(7);
});

it('changes nothing on a dry run', function () {
    StockLocation::factory()->create();
    TyreVariant::factory()->count(2)->create();

    $this->artisan('inventory:set-stock', ['--dry-run' => true])->assertSuccessful();

    expect(InventoryItem::query()->count())->toBe(0);
});

it('changes nothing when the confirmation is declined', function () {
    StockLocation::factory()->create();
    TyreVariant::factory()->create();

    $this->artisan('inventory:set-stock')->expectsConfirmation('Apply this change?', 'no')->assertSuccessful();

    expect(InventoryItem::query()->count())->toBe(0);
});

it('refuses to guess when there are several locations', function () {
    StockLocation::factory()->count(2)->create();
    TyreVariant::factory()->create();

    $this->artisan('inventory:set-stock', ['--force' => true])->assertFailed();

    expect(InventoryItem::query()->count())->toBe(0);
});

it('updates only the chosen locations, or all of them with --all-locations', function () {
    [$a, $b] = StockLocation::factory()->count(2)->create();
    TyreVariant::factory()->create();

    $this->artisan('inventory:set-stock', ['--location' => [$a->id], '--force' => true])->assertSuccessful();

    expect(InventoryItem::query()->pluck('stock_location_id')->all())->toBe([$a->id]);

    $this->artisan('inventory:set-stock', ['--all-locations' => true, '--force' => true])->assertSuccessful();

    expect(InventoryItem::query()->count())->toBe(2);
});

it('rejects an unknown location and a bad quantity', function () {
    StockLocation::factory()->create();
    TyreVariant::factory()->create();

    $this->artisan('inventory:set-stock', ['--location' => [9999], '--force' => true])->assertFailed();
    $this->artisan('inventory:set-stock', ['quantity' => '-3', '--force' => true])->assertFailed();
    $this->artisan('inventory:set-stock', ['quantity' => 'lots', '--force' => true])->assertFailed();

    expect(InventoryItem::query()->count())->toBe(0);
});
