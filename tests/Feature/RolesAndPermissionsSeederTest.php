<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\RefreshesPermissionCache;

test('seeds the six staff roles with guard_name web', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $roleNames = Role::pluck('name')->sort()->values()->all();

    expect($roleNames)->toBe([
        'customer_support',
        'ecommerce',
        'fleet',
        'operations',
        'super_admin',
        'technician',
    ]);
    expect(Role::pluck('guard_name')->unique()->all())->toBe(['web']);
    expect(Permission::pluck('guard_name')->unique()->all())->toBe(['web']);
});

test('super_admin gets manage plus the implied view permission on every manage module', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $permissions = Role::findByName('super_admin', 'web')->permissions->pluck('name')->sort()->values()->all();

    expect($permissions)->toContain('products.manage', 'products.view', 'roles-users.manage', 'roles-users.view')
        ->and($permissions)->toContain('reporting.view')
        ->and($permissions)->not->toContain('reporting.manage');
});

test('technician only holds the scoped bookings.view-own permission, never module-level orders or customers grants', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $permissions = Role::findByName('technician', 'web')->permissions->pluck('name')->all();

    expect($permissions)->toBe(['bookings.view-own']);
});

test('nobody holds plain bookings.view, only bookings.manage or bookings.view-own', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    foreach (Role::with('permissions')->get() as $role) {
        expect($role->permissions->pluck('name'))->not->toContain('bookings.view');
    }
});

test('running the seeder twice is idempotent', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::count())->toBe(6);

    $operationsPermissionCount = Role::findByName('operations', 'web')->permissions->count();
    expect($operationsPermissionCount)->toBeGreaterThan(0);
});

test('vehicles module tiers match the 2026-09-14 RBAC decision record', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $permissionsFor = fn (string $role) => Role::findByName($role, 'web')->permissions->pluck('name')->all();

    expect($permissionsFor('super_admin'))->toContain('vehicles.manage', 'vehicles.view')
        ->and($permissionsFor('operations'))->toContain('vehicles.manage', 'vehicles.view')
        ->and($permissionsFor('ecommerce'))->toContain('vehicles.view')->not->toContain('vehicles.manage')
        ->and($permissionsFor('customer_support'))->toContain('vehicles.view')->not->toContain('vehicles.manage')
        ->and($permissionsFor('fleet'))->not->toContain('vehicles.view', 'vehicles.manage')
        ->and($permissionsFor('technician'))->not->toContain('vehicles.view', 'vehicles.manage');
});

test('orders.refund is standalone, held only by super_admin and operations, never orders.manage-implied', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $permissionsFor = fn (string $role) => Role::findByName($role, 'web')->permissions->pluck('name')->all();

    expect($permissionsFor('super_admin'))->toContain('orders.refund')
        ->and($permissionsFor('operations'))->toContain('orders.refund')
        ->and($permissionsFor('customer_support'))->toContain('orders.manage')->not->toContain('orders.refund')
        ->and($permissionsFor('ecommerce'))->not->toContain('orders.refund')
        ->and($permissionsFor('fleet'))->not->toContain('orders.refund');
});

test('audit-log.view is standalone, held only by super_admin/operations/customer_support', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $permissionsFor = fn (string $role) => Role::findByName($role, 'web')->permissions->pluck('name')->all();

    expect($permissionsFor('super_admin'))->toContain('audit-log.view')
        ->and($permissionsFor('operations'))->toContain('audit-log.view')
        ->and($permissionsFor('customer_support'))->toContain('audit-log.view')
        ->and($permissionsFor('ecommerce'))->not->toContain('audit-log.view')
        ->and($permissionsFor('fleet'))->not->toContain('audit-log.view')
        ->and(Role::findByName('technician', 'web')->permissions->pluck('name')->all())->not->toContain('audit-log.view');
});

test('an unrecognised tier throws instead of silently granting nothing', function () {
    $method = new ReflectionMethod(RolesAndPermissionsSeeder::class, 'permissionNamesForTier');

    expect(fn () => $method->invoke(null, 'products', 'edit'))
        ->toThrow(LogicException::class, 'Unhandled permission tier "edit" for module "products".');
});

/**
 * Coverage for `RolesAndPermissionsSeeder::run()`'s explicit
 * `PermissionRegistrar::forgetCachedPermissions()` call, added 2026-09-23
 * after backend-tester/qa-lead flagged a suspected stale-permission-cache
 * gap: this seeder uses `WithoutModelEvents`, which suppresses the Eloquent
 * `saved`/`deleted` events `Role`/`Permission` normally use to
 * auto-invalidate spatie's permission cache
 * ({@see RefreshesPermissionCache}).
 *
 * **Investigation note, recorded here rather than silently assumed:**
 * verified directly against the installed spatie/laravel-permission 8.3.0
 * source (`vendor/spatie/laravel-permission/src/Traits/HasPermissions.php`)
 * that `Role::syncPermissions()` — the call this seeder already makes for
 * every role — routes through `givePermissionTo()`/`revokePermissionTo()`,
 * both of which call `$this->forgetCachedPermissions()` **directly and
 * unconditionally** whenever `$this instanceof Role` (not via an Eloquent
 * model event, so `WithoutModelEvents` does not suppress it). Confirmed
 * empirically too: this exact test still passes with the seeder's new
 * explicit flush temporarily removed, i.e. it does not fail-then-pass
 * across that change — `syncPermissions()`'s own pre-existing call already
 * covers the property this test asserts, for the currently-installed
 * package version. The explicit flush is kept anyway as cheap,
 * self-documented defense-in-depth (this class's own invariant, not one
 * borrowed silently from a third-party method's internal call graph that
 * could change in a future spatie release) — but this test is real coverage
 * of the black-box property (a reseed's permission changes are visible to
 * a fresh permission check with no manual cache-reset step), not proof that
 * this specific line is what makes it pass. Flagged back to
 * project-manager/backend-tester/qa-lead rather than reporting this as a
 * confirmed-and-closed bug.
 */
test('reseeding leaves permission changes visible to a fresh permission check, with no manual cache-reset step', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('operations');

    // Warm the cache against the correct, freshly-seeded state.
    expect($user->can('orders.manage'))->toBeTrue();

    // Diverge the DB from the seeder's own const without touching Eloquent
    // — a raw pivot-table write bypasses any Eloquent event entirely,
    // whether or not spatie's own cache invalidation happens to be
    // event-based for a given call path.
    $role = Role::findByName('operations', 'web');
    $permission = Permission::findByName('orders.manage', 'web');
    DB::table('role_has_permissions')
        ->where('role_id', $role->id)
        ->where('permission_id', $permission->id)
        ->delete();

    // The cache still reflects the pre-divergence state — proves the cache
    // genuinely didn't just happen to be empty/uncached already.
    expect($user->can('orders.manage'))->toBeTrue();

    // Reseeding restores orders.manage for operations via syncPermissions()
    // — asserting below that this is visible to a fresh check with no
    // manual cache-reset step, see this test's docblock for what that
    // does/doesn't prove about which specific code path is responsible.
    $this->seed(RolesAndPermissionsSeeder::class);

    $freshUser = User::find($user->id);
    expect($freshUser->can('orders.manage'))->toBeTrue();
});

/**
 * Coverage for `RolesAndPermissionsSeeder::run()`'s start-of-run
 * `forgetCachedPermissions()` call, added 2026-09-24 — the sharper root
 * cause found after the 2026-09-23 end-of-run-only fix (above) still let
 * this recur live on the shared dev DB. Reproduces a long-lived process's
 * permission cache being warm *before* a reseed starts: this test warms
 * the cache while no permissions exist yet, then seeds. `WithoutModelEvents`
 * suppresses spatie's own save-triggered cache invalidation for every
 * `firstOrCreate`/`syncPermissions` write this seeder makes, so without the
 * start-of-run flush, `syncPermissions()` for the first role would resolve
 * a permission that was `firstOrCreate`'d moments earlier in this same run
 * against this stale, permission-less cache and throw
 * `PermissionDoesNotExist` — confirmed empirically: this test does
 * fail-then-pass across that change (temporarily removing the start-of-run
 * call reproduces the exact exception below).
 */
test('reseeding after a stale walk-in permission cache does not throw PermissionDoesNotExist', function () {
    app(PermissionRegistrar::class)->getPermissions();

    expect(fn () => $this->seed(RolesAndPermissionsSeeder::class))
        ->not->toThrow(PermissionDoesNotExist::class);

    expect(Role::findByName('super_admin', 'web')->permissions->pluck('name'))
        ->toContain('audit-log.view', 'orders.refund');
});
