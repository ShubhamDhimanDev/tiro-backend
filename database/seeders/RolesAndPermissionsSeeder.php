<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Every module gated by fine-grained `{module}.view` / `{module}.manage`
     * permissions. `reporting` has no meaningful "manage" tier (nothing to
     * create/edit) — its `.manage` permission is still created for
     * consistency but is never assigned to any role.
     *
     * @var list<string>
     */
    private const MODULES = [
        'products',
        'inventory',
        'orders',
        'bookings',
        'customers',
        'locations',
        'promotions',
        'content',
        'reporting',
        'roles-users',
        'vehicles',
    ];

    /**
     * Permission bundle per role, per module. `manage` implies `view` (both
     * permissions are assigned). Modules omitted for a role resolve to
     * `none`. Technician is intentionally absent here — it only ever holds
     * the standalone `bookings.view-own` permission, assigned separately
     * below, never any `{module}.view` / `{module}.manage` pair.
     *
     * @var array<string, array<string, 'view'|'manage'>>
     */
    private const ROLE_MODULE_TIERS = [
        'super_admin' => [
            'products' => 'manage',
            'inventory' => 'manage',
            'orders' => 'manage',
            'bookings' => 'manage',
            'customers' => 'manage',
            'locations' => 'manage',
            'promotions' => 'manage',
            'content' => 'manage',
            'reporting' => 'view',
            'roles-users' => 'manage',
            'vehicles' => 'manage',
        ],
        'ecommerce' => [
            'products' => 'manage',
            'inventory' => 'view',
            'orders' => 'view',
            'promotions' => 'manage',
            'content' => 'manage',
            'reporting' => 'view',
            'vehicles' => 'view',
        ],
        'operations' => [
            'products' => 'view',
            'inventory' => 'manage',
            'orders' => 'manage',
            'bookings' => 'manage',
            'customers' => 'view',
            'locations' => 'manage',
            'promotions' => 'view',
            'reporting' => 'view',
            'vehicles' => 'manage',
        ],
        'customer_support' => [
            'products' => 'view',
            'orders' => 'manage',
            'bookings' => 'manage',
            'customers' => 'manage',
            'locations' => 'view',
            'promotions' => 'view',
            'vehicles' => 'view',
        ],
        'fleet' => [
            'bookings' => 'manage',
            'locations' => 'view',
            'reporting' => 'view',
        ],
    ];

    /**
     * Seed the six admin/staff roles and their fine-grained permissions.
     *
     * Idempotent — safe to re-run (uses `firstOrCreate`/`syncPermissions`,
     * never a bare `create`).
     */
    public function run(): void
    {
        foreach (self::MODULES as $module) {
            Permission::firstOrCreate(['name' => "{$module}.view", 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => "{$module}.manage", 'guard_name' => 'web']);
        }

        // Distinct from `bookings.view` — nobody holds plain `bookings.view`
        // (Operations/CS/Fleet hold `bookings.manage`, a superset). Only
        // Technician holds this permission.
        Permission::firstOrCreate(['name' => 'bookings.view-own', 'guard_name' => 'web']);

        foreach (self::ROLE_MODULE_TIERS as $roleName => $moduleTiers) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $permissionNames = collect($moduleTiers)
                ->flatMap(fn (string $tier, string $module) => self::permissionNamesForTier($module, $tier))
                ->values()
                ->all();

            $role->syncPermissions($permissionNames);
        }

        $technician = Role::firstOrCreate(['name' => 'technician', 'guard_name' => 'web']);
        $technician->syncPermissions(['bookings.view-own']);
    }

    /**
     * Resolve the permission name(s) a `$module` + `$tier` pair grants.
     * `manage` implies `view` for every module except `bookings` — see the
     * comment above `bookings.view-own` in {@see self::run()}. Plain
     * `bookings.view` is never assigned to a role.
     *
     * `$tier` is declared as plain `string`, not the `'view'|'manage'`
     * literal type {@see self::ROLE_MODULE_TIERS} is documented as holding,
     * because that documented type is unenforced at runtime — nothing stops
     * a future edit to the const (or another caller) from passing something
     * else. The `default` arm exists for exactly that case: this project
     * has hit the "silently unhandled mapping value" class of bug three
     * times already this phase, so an unrecognised tier throws immediately
     * with a message identifying the module and tier, rather than
     * degrading to a missing/incomplete permission grant.
     *
     * @return list<string>
     */
    private static function permissionNamesForTier(string $module, string $tier): array
    {
        return match (true) {
            $module === 'bookings' && $tier === 'manage' => ["{$module}.manage"],
            $tier === 'manage' => ["{$module}.manage", "{$module}.view"],
            $tier === 'view' => ["{$module}.view"],
            default => throw new LogicException("Unhandled permission tier \"{$tier}\" for module \"{$module}\"."),
        };
    }
}
