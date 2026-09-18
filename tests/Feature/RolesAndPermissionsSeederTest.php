<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

test('an unrecognised tier throws instead of silently granting nothing', function () {
    $method = new ReflectionMethod(RolesAndPermissionsSeeder::class, 'permissionNamesForTier');

    expect(fn () => $method->invoke(null, 'products', 'edit'))
        ->toThrow(LogicException::class, 'Unhandled permission tier "edit" for module "products".');
});
