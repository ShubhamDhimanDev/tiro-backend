<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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
     * Standalone permissions (outside the `{module}.view`/`{module}.manage`
     * tier matrix) layered on top of whatever tier permissions a role
     * already holds via {@see ROLE_MODULE_TIERS}. Technician's equivalent
     * (`bookings.view-own`) is assigned separately below instead, since
     * Technician has no row in `ROLE_MODULE_TIERS` at all.
     *
     * `audit-log.view` (Phase 6): super_admin, operations, customer_support
     * only — deliberately NOT Ecommerce, Fleet, or Technician.
     * `reporting.view` is already held by Fleet for legitimate
     * booking/van reasons, but `AuditLog` rows carry Customer-PII order
     * diffs and Roles & Users change history Fleet has no business seeing —
     * that's why this is a standalone permission, not folded into
     * `reporting.view`.
     *
     * @var array<string, list<string>>
     */
    private const STANDALONE_PERMISSIONS_BY_ROLE = [
        'super_admin' => ['orders.refund', 'audit-log.view'],
        'operations' => ['orders.refund', 'audit-log.view'],
        'customer_support' => ['audit-log.view'],
    ];

    /**
     * Seed the six admin/staff roles and their fine-grained permissions.
     *
     * Idempotent — safe to re-run (uses `firstOrCreate`/`syncPermissions`,
     * never a bare `create`).
     *
     * **Explicit permission-cache invalidation, added 2026-09-23** (found by
     * backend-tester/qa-lead while testing Phase 5's admin permission
     * gates): this class uses {@see WithoutModelEvents}, so Eloquent model
     * events — the mechanism spatie/laravel-permission normally hooks to
     * auto-flush its permission cache on a role/permission write — never
     * fire here. Without an explicit flush, a role/permission change made
     * by re-running this seeder (which has happened in every phase so far)
     * would only take effect once the cache's own TTL naturally expires or
     * someone manually runs `permission:cache-reset` — a genuinely-entitled
     * user can get a stale-cache `403` in the meantime. False-negative
     * direction only (never grants anything not actually seeded), not a
     * privilege-escalation risk, but a real operational footgun on a
     * project where this seeder is touched almost every phase. Fixed with a
     * single explicit flush at the end of `run()` rather than dropping
     * `WithoutModelEvents` — that trait's idempotency/performance tradeoff
     * for this seeder's bulk firstOrCreate/syncPermissions calls is
     * unrelated to the caching gap and isn't being reconsidered here.
     *
     * **Second flush added 2026-09-24** (recurrence of the same failure
     * class, sharper root cause): the original fix above only flushed at
     * the *end* of `run()`, which is too late for a long-lived server
     * process whose permission cache is already warm/stale *before* this
     * reseed even starts — `syncPermissions()` mid-run needs to resolve
     * permissions that may have just been `firstOrCreate`'d moments
     * earlier in this same run, and a stale walk-in cache can make that
     * lookup throw `PermissionDoesNotExist` before the end-of-run flush
     * ever gets a chance to help. So there are now two calls, not one —
     * intentionally, not a duplication to "clean up": the start-of-run
     * flush guards against a stale cache this run inherited, the
     * end-of-run flush guards against this run's own writes going stale
     * for whatever reads happen next.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::MODULES as $module) {
            Permission::firstOrCreate(['name' => "{$module}.view", 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => "{$module}.manage", 'guard_name' => 'web']);
        }

        // Distinct from `bookings.view` — nobody holds plain `bookings.view`
        // (Operations/CS/Fleet hold `bookings.manage`, a superset). Only
        // Technician holds this permission.
        Permission::firstOrCreate(['name' => 'bookings.view-own', 'guard_name' => 'web']);

        // Standalone, same shape as `bookings.view-own` above — outside the
        // {module}.view/{module}.manage tier matrix. A refund moves real
        // money out of the business, a materially higher-risk action than
        // the rest of the `orders.manage` bundle (Customer Support keeps
        // `orders.manage` for search/view/cancel/status-update, but not
        // this). See docs/architecture/06-open-decisions.md item 17 and
        // docs/architecture/07-admin-auth-permissions.md §3.2.
        Permission::firstOrCreate(['name' => 'orders.refund', 'guard_name' => 'web']);

        // Standalone (Phase 6) — see STANDALONE_PERMISSIONS_BY_ROLE's
        // docblock for why this sits outside the `content`/`reporting`
        // module tiers rather than folded into either.
        Permission::firstOrCreate(['name' => 'audit-log.view', 'guard_name' => 'web']);

        foreach (self::ROLE_MODULE_TIERS as $roleName => $moduleTiers) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $permissionNames = collect($moduleTiers)
                ->flatMap(fn (string $tier, string $module) => self::permissionNamesForTier($module, $tier))
                ->merge(self::STANDALONE_PERMISSIONS_BY_ROLE[$roleName] ?? [])
                ->unique()
                ->values()
                ->all();

            $role->syncPermissions($permissionNames);
        }

        $technician = Role::firstOrCreate(['name' => 'technician', 'guard_name' => 'web']);
        $technician->syncPermissions(['bookings.view-own']);

        // See this method's docblock — WithoutModelEvents means nothing
        // else invalidates spatie's permission cache on a reseed, so this
        // explicit flush is the only thing that does. Always run, whether
        // or not any row above actually changed — cheap, and correctness
        // here matters more than skipping a no-op cache clear.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
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
