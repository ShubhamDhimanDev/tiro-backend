<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Bookings\CancellationPolicyController;
use App\Http\Controllers\Admin\Bookings\DispatchBoardController;
use App\Http\Controllers\Admin\Bookings\TechnicianController;
use App\Http\Controllers\Admin\Bookings\TechnicianLoginController;
use App\Http\Controllers\Admin\Bookings\TechnicianShiftController;
use App\Http\Controllers\Admin\Bookings\VanController;
use App\Http\Controllers\Admin\Content\ContentPageController;
use App\Http\Controllers\Admin\Content\FaqController;
use App\Http\Controllers\Admin\Customers\CustomerController;
use App\Http\Controllers\Admin\Inventory\InventoryItemController;
use App\Http\Controllers\Admin\Inventory\ServiceZoneStockLocationController;
use App\Http\Controllers\Admin\Inventory\StockLocationController;
use App\Http\Controllers\Admin\Locations\ServiceZoneController;
use App\Http\Controllers\Admin\Locations\ServiceZoneSuburbController;
use App\Http\Controllers\Admin\Locations\StateController;
use App\Http\Controllers\Admin\Locations\SuburbController;
use App\Http\Controllers\Admin\Orders\OrderController;
use App\Http\Controllers\Admin\Orders\OrderRefundController;
use App\Http\Controllers\Admin\Products\BrandController;
use App\Http\Controllers\Admin\Products\CatalogImportController;
use App\Http\Controllers\Admin\Products\TyreModelController;
use App\Http\Controllers\Admin\Products\TyreVariantController;
use App\Http\Controllers\Admin\Promotions\PriceGuaranteeClaimController;
use App\Http\Controllers\Admin\Promotions\PriceRuleController;
use App\Http\Controllers\Admin\Promotions\PromotionController;
use App\Http\Controllers\Admin\Promotions\PromotionEligibilityController;
use App\Http\Controllers\Admin\Reporting\ReportingController;
use App\Http\Controllers\Admin\Reviews\ReviewController;
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

            // Bulk supplier product-export (WooCommerce CSV) importer —
            // same reasoning as the Vehicles-import route group above: an
            // upload-only screen has no meaningful read-only mode, so both
            // GET (form) and POST (process) sit behind `products.manage`
            // alone rather than being split across `products.view`/`.manage`.
            Route::get('products/import', [CatalogImportController::class, 'index'])->name('products.import.index');
            Route::post('products/import', [CatalogImportController::class, 'store'])->name('products.import.store');
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

        // Bookings — fleet roster (vans/technicians/shifts), cancellation
        // policy config, and the dispatch/booking board. Nobody holds plain
        // `bookings.view` (Operations/CS/Fleet/Super Admin hold
        // `bookings.manage`, a superset); only a Technician's scoped
        // `bookings.view-own` sits below `manage` — see
        // docs/architecture/07-admin-auth-permissions.md §3.1 footnote 2 and
        // RolesAndPermissionsSeeder. The dispatch board's index route is the
        // one screen a `view-own`-scoped Technician can reach; every other
        // Bookings-module route (roster CRUD, cancellation policy, and the
        // board's own mutation/preview actions) requires `bookings.manage`.
        Route::middleware('permission:bookings.manage|bookings.view-own')->group(function () {
            Route::get('dispatch', [DispatchBoardController::class, 'index'])->name('dispatch.index');
        });
        Route::middleware('permission:bookings.manage')->group(function () {
            Route::get('dispatch/bookings/{booking}/availability', [DispatchBoardController::class, 'availability'])->name('dispatch.bookings.availability');
            Route::patch('dispatch/bookings/{booking}/move', [DispatchBoardController::class, 'move'])->name('dispatch.bookings.move');
            Route::post('dispatch/bookings/{booking}/cancel', [DispatchBoardController::class, 'cancel'])->name('dispatch.bookings.cancel');

            Route::get('bookings/vans', [VanController::class, 'index'])->name('bookings.vans.index');
            Route::post('bookings/vans', [VanController::class, 'store'])->name('bookings.vans.store');
            Route::put('bookings/vans/{van}', [VanController::class, 'update'])->name('bookings.vans.update');

            Route::get('bookings/technicians', [TechnicianController::class, 'index'])->name('bookings.technicians.index');
            Route::post('bookings/technicians', [TechnicianController::class, 'store'])->name('bookings.technicians.store');
            Route::put('bookings/technicians/{technician}', [TechnicianController::class, 'update'])->name('bookings.technicians.update');

            Route::get('bookings/shifts', [TechnicianShiftController::class, 'index'])->name('bookings.shifts.index');
            Route::post('bookings/shifts', [TechnicianShiftController::class, 'store'])->name('bookings.shifts.store');
            Route::put('bookings/shifts/{technicianShift}', [TechnicianShiftController::class, 'update'])->name('bookings.shifts.update');

            Route::get('bookings/cancellation-policies', [CancellationPolicyController::class, 'index'])->name('bookings.cancellation-policies.index');
            Route::post('bookings/cancellation-policies', [CancellationPolicyController::class, 'store'])->name('bookings.cancellation-policies.store');
            Route::put('bookings/cancellation-policies/{cancellationPolicy}', [CancellationPolicyController::class, 'update'])->name('bookings.cancellation-policies.update');
            Route::delete('bookings/cancellation-policies/{cancellationPolicy}', [CancellationPolicyController::class, 'destroy'])->name('bookings.cancellation-policies.destroy');
        });

        // Orders — search/view (`orders.view`, a subset every
        // `orders.manage` holder also gets — see
        // RolesAndPermissionsSeeder::permissionNamesForTier()), cancel/
        // status-update (`orders.manage`), refund (`orders.refund`,
        // deliberately its own permission, not folded into
        // `orders.manage`): Customer Support keeps `orders.manage` for
        // search/view/cancel/status-update, but a refund moves real money
        // and is escalated to Operations/Super Admin — see
        // docs/architecture/07-admin-auth-permissions.md §3.2.
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        });
        Route::middleware('permission:orders.manage')->group(function () {
            Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
            Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status.update');
        });

        // `idempotency` middleware requires a client-supplied
        // `Idempotency-Key` header, the same format-validation-only
        // convention already used on `POST /api/v1/bookings`/
        // `POST /api/v1/orders` — a real double-click/back-button/
        // network-lag double-submit is two separate HTTP requests, and
        // without a client-supplied key each would get its own
        // server-generated one, defeating dedup for a partial refund. See
        // `RefundService::refund()`'s docblock for the actual dedup lookup.
        Route::middleware(['permission:orders.refund', 'idempotency'])->group(function () {
            Route::post('orders/{order}/refund', [OrderRefundController::class, 'store'])->name('orders.refund');
        });

        // Customers — search/view only, no mutation actions this phase (see
        // `CustomerController`'s docblock). Gated entirely on
        // `customers.view` (which `customers.manage` holders also receive —
        // see `RolesAndPermissionsSeeder::permissionNamesForTier()`), same
        // "one group covers both tiers" convention as Orders above. The
        // detail screen's saved-vehicles/addresses/order-history/recent-
        // notifications panels all fold into this same gate — no
        // module-specific sub-permission, per this phase's task brief.
        Route::middleware('permission:customers.view')->group(function () {
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        });

        // Promotions — campaign CRUD (Promotion + nested
        // PromotionEligibility rows), the price-guarantee claim review
        // queue, and PriceRule (per-zone service-fee) CRUD. Mutations gated
        // on `promotions.manage` only (Ecommerce/Super Admin) — creating/
        // editing a campaign, approving a price-guarantee claim, etc.
        //
        // **RBAC hardening pass fix, 2026-09-23 (Phase 6):** the three
        // read-only `index` routes below were ALSO gated `promotions.manage`
        // until this fix, silently 403-ing Operations/Customer Support even
        // though `RolesAndPermissionsSeeder` already grants both roles
        // `promotions.view` (matching the Phase 6 permission matrix's
        // "Promotions: view = Operations, view = Customer Support" cells) —
        // a genuine route/seeder drift, not a deliberate design choice; see
        // this phase's handback for the full writeup. Split into their own
        // `promotions.view` group here (a `promotions.manage` holder still
        // reaches these too, since `manage` always implies `view` — see
        // `RolesAndPermissionsSeeder::permissionNamesForTier()`). The old
        // "not Customer Support and not locations.manage" comment
        // previously here (citing
        // docs/architecture/07-admin-auth-permissions.md §3.2) described
        // the pre-fix *mutation* gating correctly — that part is unchanged
        // — but had been read as covering the index routes too, which this
        // fix corrects.
        Route::middleware('permission:promotions.view')->group(function () {
            Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
            Route::get('price-guarantee-claims', [PriceGuaranteeClaimController::class, 'index'])->name('price-guarantee-claims.index');
            Route::get('price-rules', [PriceRuleController::class, 'index'])->name('price-rules.index');
        });

        Route::middleware('permission:promotions.manage')->group(function () {
            Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
            Route::put('promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
            Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');

            Route::post('promotions/{promotion}/eligibilities', [PromotionEligibilityController::class, 'store'])->name('promotions.eligibilities.store');
            Route::delete('promotions/{promotion}/eligibilities/{promotionEligibility}', [PromotionEligibilityController::class, 'destroy'])->name('promotions.eligibilities.destroy');

            Route::post('price-guarantee-claims/{priceGuaranteeClaim}/approve', [PriceGuaranteeClaimController::class, 'approve'])->name('price-guarantee-claims.approve');
            Route::post('price-guarantee-claims/{priceGuaranteeClaim}/reject', [PriceGuaranteeClaimController::class, 'reject'])->name('price-guarantee-claims.reject');

            Route::post('price-rules', [PriceRuleController::class, 'store'])->name('price-rules.store');
            Route::put('price-rules/{priceRule}', [PriceRuleController::class, 'update'])->name('price-rules.update');
            Route::delete('price-rules/{priceRule}', [PriceRuleController::class, 'destroy'])->name('price-rules.destroy');
        });

        // Technician login provisioning — deliberately its own permission
        // tier (`roles-users.manage`, Super Admin only), not folded into
        // `bookings.manage` above. See
        // App\Http\Requests\Admin\Bookings\CreateTechnicianLoginRequest's
        // docblock for the reasoning and the open question flagged from it.
        Route::middleware('permission:roles-users.manage')->group(function () {
            Route::post('bookings/technicians/{technician}/login', [TechnicianLoginController::class, 'store'])->name('bookings.technicians.login.store');
        });

        // Content — ContentPage (5 types, one screen family) + Faq (global
        // and page-scoped) admin CRUD. Gated on `content.view`/
        // `content.manage`, per the Phase 6 task brief — verified against
        // `RolesAndPermissionsSeeder`: super_admin and ecommerce hold
        // `manage` (which implies `view`), every other role holds `none`
        // for the `content` module, so there is no standalone
        // `content.view`-only role today; the split still exists for
        // forward-compatibility with the seeder's own
        // `permissionNamesForTier()` convention (every module gets both).
        Route::middleware('permission:content.view')->group(function () {
            Route::get('content/pages', [ContentPageController::class, 'index'])->name('content.pages.index');
            Route::get('content/faqs', [FaqController::class, 'index'])->name('content.faqs.index');
        });
        Route::middleware('permission:content.manage')->group(function () {
            Route::post('content/pages', [ContentPageController::class, 'store'])->name('content.pages.store');
            Route::put('content/pages/{contentPage}', [ContentPageController::class, 'update'])->name('content.pages.update');
            Route::delete('content/pages/{contentPage}', [ContentPageController::class, 'destroy'])->name('content.pages.destroy');

            Route::post('content/faqs', [FaqController::class, 'store'])->name('content.faqs.store');
            Route::put('content/faqs/{faq}', [FaqController::class, 'update'])->name('content.faqs.update');
            Route::delete('content/faqs/{faq}', [FaqController::class, 'destroy'])->name('content.faqs.destroy');
        });

        // Reviews — Phase 8, gated on the same `content.view`/
        // `content.manage` pair as the rest of the Content module (no new
        // permission — verified against `RolesAndPermissionsSeeder`, same
        // as Content above). No create/delete: review content only ever
        // originates from `reviews:sync-google`
        // ({@see App\Console\Commands\SyncGoogleReviewsCommand}), synced
        // daily and via the `resync` lever below.
        Route::middleware('permission:content.view')->group(function () {
            Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
        });
        Route::middleware('permission:content.manage')->group(function () {
            Route::patch('reviews/{review}', [ReviewController::class, 'update'])->name('reviews.update');
            Route::post('reviews/resync', [ReviewController::class, 'resync'])->name('reviews.resync');
        });

        // Reporting — 5 live-query dashboards (sales/bookings/conversion/
        // cancellation/product-performance), all read-only, one controller
        // action per the Phase 6 task brief's single
        // `GET /admin/reporting/{dashboard}` route. Gated
        // `permission:reporting.view` — verified against
        // `RolesAndPermissionsSeeder`: super_admin/ecommerce/operations/
        // fleet hold it, customer_support does not (no `reporting` entry in
        // its module-tier row — see that seeder's own module-tier table).
        Route::middleware('permission:reporting.view')->group(function () {
            Route::get('reporting/{dashboard}', [ReportingController::class, 'show'])->name('reporting.show');
        });

        // Audit log viewer — gated on the standalone `audit-log.view`
        // permission (super_admin/operations/customer_support only, NOT
        // ecommerce/fleet/technician — see `RolesAndPermissionsSeeder`'s
        // `STANDALONE_PERMISSIONS_BY_ROLE` docblock for why this sits
        // outside the `content`/`reporting` module tiers).
        Route::middleware('permission:audit-log.view')->group(function () {
            Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
        });
    });
