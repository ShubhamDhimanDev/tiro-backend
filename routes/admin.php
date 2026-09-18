<?php

use App\Http\Controllers\Admin\Inventory\InventoryItemController;
use App\Http\Controllers\Admin\Inventory\ServiceZoneStockLocationController;
use App\Http\Controllers\Admin\Inventory\StockLocationController;
use App\Http\Controllers\Admin\Locations\ServiceZoneController;
use App\Http\Controllers\Admin\Locations\ServiceZoneSuburbController;
use App\Http\Controllers\Admin\Locations\StateController;
use App\Http\Controllers\Admin\Locations\SuburbController;
use App\Http\Controllers\Admin\Products\BrandController;
use App\Http\Controllers\Admin\Products\TyreModelController;
use App\Http\Controllers\Admin\Products\TyreVariantController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\Vehicles\VehicleController;
use App\Http\Controllers\Admin\Vehicles\VehicleFitmentImportController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', EnsureTwoFactorEnabled::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::middleware('permission:roles-users.manage')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
        });

        // Products — brand -> its models -> each model's size variants.
        Route::middleware('permission:products.view')->group(function () {
            Route::get('products/brands', [BrandController::class, 'index'])->name('products.brands.index');
            Route::get('products/brands/{brand}', [TyreModelController::class, 'index'])->name('products.brands.models.index');
            Route::get('products/models/{tyreModel}', [TyreVariantController::class, 'index'])->name('products.models.variants.index');
        });
        Route::middleware('permission:products.manage')->group(function () {
            Route::post('products/brands', [BrandController::class, 'store'])->name('products.brands.store');
            Route::put('products/brands/{brand}', [BrandController::class, 'update'])->name('products.brands.update');
            Route::post('products/brands/{brand}/models', [TyreModelController::class, 'store'])->name('products.brands.models.store');
            Route::put('products/models/{tyreModel}', [TyreModelController::class, 'update'])->name('products.models.update');
            Route::post('products/models/{tyreModel}/variants', [TyreVariantController::class, 'store'])->name('products.models.variants.store');
            Route::put('products/variants/{tyreVariant}', [TyreVariantController::class, 'update'])->name('products.variants.update');
        });

        // Inventory — stock locations, their stock rows, and which zones
        // each location backs.
        Route::middleware('permission:inventory.view')->group(function () {
            Route::get('inventory/locations', [StockLocationController::class, 'index'])->name('inventory.locations.index');
            Route::get('inventory/locations/{stockLocation}', [InventoryItemController::class, 'index'])->name('inventory.locations.items.index');
        });
        Route::middleware('permission:inventory.manage')->group(function () {
            Route::post('inventory/locations', [StockLocationController::class, 'store'])->name('inventory.locations.store');
            Route::put('inventory/locations/{stockLocation}', [StockLocationController::class, 'update'])->name('inventory.locations.update');
            Route::delete('inventory/locations/{stockLocation}', [StockLocationController::class, 'destroy'])->name('inventory.locations.destroy');
            Route::post('inventory/locations/{stockLocation}/items', [InventoryItemController::class, 'store'])->name('inventory.locations.items.store');
            Route::put('inventory/items/{inventoryItem}', [InventoryItemController::class, 'update'])->name('inventory.items.update');
            Route::delete('inventory/items/{inventoryItem}', [InventoryItemController::class, 'destroy'])->name('inventory.items.destroy');
            Route::post('inventory/locations/{stockLocation}/zones', [ServiceZoneStockLocationController::class, 'store'])->name('inventory.locations.zones.store');
            Route::delete('inventory/locations/{stockLocation}/zones/{serviceZone}', [ServiceZoneStockLocationController::class, 'destroy'])->name('inventory.locations.zones.destroy');
        });

        // Locations — states, service zones (radius/suburb_list), suburbs,
        // and the suburb_list <-> suburb membership pivot.
        Route::middleware('permission:locations.view')->group(function () {
            Route::get('locations/states', [StateController::class, 'index'])->name('locations.states.index');
            Route::get('locations/zones', [ServiceZoneController::class, 'index'])->name('locations.zones.index');
            Route::get('locations/suburbs', [SuburbController::class, 'index'])->name('locations.suburbs.index');
        });
        Route::middleware('permission:locations.manage')->group(function () {
            Route::post('locations/states', [StateController::class, 'store'])->name('locations.states.store');
            Route::put('locations/states/{state}', [StateController::class, 'update'])->name('locations.states.update');
            Route::patch('locations/states/{state}/toggle-active', [StateController::class, 'toggleActive'])->name('locations.states.toggle-active');

            Route::post('locations/zones', [ServiceZoneController::class, 'store'])->name('locations.zones.store');
            Route::put('locations/zones/{serviceZone}', [ServiceZoneController::class, 'update'])->name('locations.zones.update');
            Route::post('locations/zones/{serviceZone}/suburbs', [ServiceZoneSuburbController::class, 'store'])->name('locations.zones.suburbs.store');
            Route::delete('locations/zones/{serviceZone}/suburbs/{suburb}', [ServiceZoneSuburbController::class, 'destroy'])->name('locations.zones.suburbs.destroy');

            Route::post('locations/suburbs', [SuburbController::class, 'store'])->name('locations.suburbs.store');
            Route::put('locations/suburbs/{suburb}', [SuburbController::class, 'update'])->name('locations.suburbs.update');
            Route::delete('locations/suburbs/{suburb}', [SuburbController::class, 'destroy'])->name('locations.suburbs.destroy');
        });

        // Vehicles — Vehicle + nested VehicleFitment row-level CRUD, plus
        // the bulk CSV/JSON import screen. New module, RBAC decision
        // 2026-09-14 — see docs/architecture/07-admin-auth-permissions.md §3.1.
        // The import screen is gated entirely behind vehicles.manage (both
        // viewing the upload form and processing it) — there's no
        // meaningful read-only version of a screen whose only purpose is a
        // mutation.
        Route::middleware('permission:vehicles.view')->group(function () {
            Route::get('vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
        });
        Route::middleware('permission:vehicles.manage')->group(function () {
            Route::post('vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
            Route::put('vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
            Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy'])->name('vehicles.destroy');

            Route::get('vehicles/import', [VehicleFitmentImportController::class, 'index'])->name('vehicles.import.index');
            Route::post('vehicles/import', [VehicleFitmentImportController::class, 'store'])->name('vehicles.import.store');
        });
    });
