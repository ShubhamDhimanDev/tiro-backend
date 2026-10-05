<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Task-breakdown Phase 0 test-coverage row: "route middleware AND
 * policy-level denial parity — a user without a permission must be denied
 * identically whether they hit the route directly or would have had a UI
 * button hidden."
 *
 * There is no dedicated Policy class for the roles-users module yet (only
 * the `permission:roles-users.manage` route middleware in routes/admin.php)
 * — but the same spatie `HasRoles::getAllPermissions()` call feeds both the
 * middleware's own check (which resolves through Laravel's Gate) and the
 * `auth.permissions` Inertia shared prop super-admin-agent's `<Can>`
 * wrapper reads to decide whether to render the "Invite user" button (see
 * App\Http\Middleware\HandleInertiaRequests). Asserting the Gate directly,
 * for the full role matrix, proves that source of truth is correct — a
 * button hidden by that prop and a direct route hit are guaranteed to agree
 * because they are backed by the identical permission set, not two
 * independently-maintained checks that could drift apart.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('only super_admin holds the roles-users.manage gate across the full role matrix', function (string $role, bool $expectedAllowed) {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('roles-users.manage'))->toBe($expectedAllowed);
})->with([
    'super_admin is allowed' => ['super_admin', true],
    'ecommerce is denied' => ['ecommerce', false],
    'operations is denied' => ['operations', false],
    'customer_support is denied' => ['customer_support', false],
    'fleet is denied' => ['fleet', false],
    'technician is denied' => ['technician', false],
]);

test('the admin.users.store route denies every non-super_admin role identically to the Gate check', function (string $role) {
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    expect($user->can('roles-users.manage'))->toBeFalse();

    $this->actingAs($user)->post(route('admin.users.store'), [
        'name' => 'New Hire',
        'email' => 'new-hire@example.com',
        'role' => 'operations',
    ])->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'new-hire@example.com']);
})->with(['ecommerce', 'operations', 'customer_support', 'fleet', 'technician']);

test('the admin.users.store route allows super_admin identically to the Gate check', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('super_admin');

    expect($user->can('roles-users.manage'))->toBeTrue();

    $this->actingAs($user)->post(route('admin.users.store'), [
        'name' => 'New Hire',
        'email' => 'new-hire@example.com',
        'role' => 'operations',
    ])->assertRedirect();

    $this->assertDatabaseHas('users', ['email' => 'new-hire@example.com']);
});

/**
 * Phase 6 RBAC hardening pass: extends this harness's Gate-level parity
 * pattern (previously only `roles-users.manage`, see the class docblock)
 * across every `{module}.view`/`{module}.manage` cell in the full
 * Phase 6 permission matrix — one Gate-level check per module per role,
 * independent of `RolesAndPermissionsSeeder`'s own `ROLE_MODULE_TIERS`
 * const (this table is hand-transcribed from the matrix directly, not
 * derived from that const, so a future accidental edit to the const would
 * actually be caught here rather than the test trivially agreeing with
 * whatever the const already says).
 *
 * `bookings` (no plain `.view`, only `.manage`/`.view-own`) and `reporting`
 * (no `.manage` tier ever assigned) are intentionally excluded from this
 * generic loop and covered by their own dedicated tests below instead,
 * since both deviate from the plain view-implies-nothing/manage-implies-
 * view shape every other module here follows.
 *
 * `customers`/`content`/`reporting` have no admin routes/controllers built
 * yet (Customers: never, in any phase to date; Content/Reporting: this
 * phase's new modules, super-admin-agent's follow-on-round controllers) —
 * this test only asserts the underlying Spatie Gate, which is real and
 * checkable regardless of whether a route exists yet to enforce it.
 */
test('every module tier in the Phase 6 permission matrix holds at the Gate level', function (string $module, string $role, string $expectedTier) {
    $user = User::factory()->create();
    $user->assignRole($role);

    match ($expectedTier) {
        'manage' => expect($user->can("{$module}.manage"))->toBeTrue()
            ->and($user->can("{$module}.view"))->toBeTrue(),
        'view' => expect($user->can("{$module}.view"))->toBeTrue()
            ->and($user->can("{$module}.manage"))->toBeFalse(),
        'none' => expect($user->can("{$module}.view"))->toBeFalse()
            ->and($user->can("{$module}.manage"))->toBeFalse(),
        default => throw new LogicException("Unhandled expected tier \"{$expectedTier}\" in test dataset."),
    };
})->with(function () {
    // module => [role => tier]. Hand-transcribed from the Phase 6 task
    // brief's permission matrix table verbatim.
    $matrix = [
        'products' => ['super_admin' => 'manage', 'ecommerce' => 'manage', 'operations' => 'view', 'customer_support' => 'view', 'technician' => 'none', 'fleet' => 'none'],
        'inventory' => ['super_admin' => 'manage', 'ecommerce' => 'view', 'operations' => 'manage', 'customer_support' => 'none', 'technician' => 'none', 'fleet' => 'none'],
        'orders' => ['super_admin' => 'manage', 'ecommerce' => 'view', 'operations' => 'manage', 'customer_support' => 'manage', 'technician' => 'none', 'fleet' => 'none'],
        'customers' => ['super_admin' => 'manage', 'ecommerce' => 'none', 'operations' => 'view', 'customer_support' => 'manage', 'technician' => 'none', 'fleet' => 'none'],
        'locations' => ['super_admin' => 'manage', 'ecommerce' => 'none', 'operations' => 'manage', 'customer_support' => 'view', 'technician' => 'none', 'fleet' => 'view'],
        'promotions' => ['super_admin' => 'manage', 'ecommerce' => 'manage', 'operations' => 'view', 'customer_support' => 'view', 'technician' => 'none', 'fleet' => 'none'],
        'content' => ['super_admin' => 'manage', 'ecommerce' => 'manage', 'operations' => 'none', 'customer_support' => 'none', 'technician' => 'none', 'fleet' => 'none'],
        'roles-users' => ['super_admin' => 'manage', 'ecommerce' => 'none', 'operations' => 'none', 'customer_support' => 'none', 'technician' => 'none', 'fleet' => 'none'],
        'vehicles' => ['super_admin' => 'manage', 'ecommerce' => 'view', 'operations' => 'manage', 'customer_support' => 'view', 'technician' => 'none', 'fleet' => 'none'],
    ];

    $cases = [];

    foreach ($matrix as $module => $roleTiers) {
        foreach ($roleTiers as $role => $tier) {
            $cases["{$module} / {$role} => {$tier}"] = [$module, $role, $tier];
        }
    }

    return $cases;
});

test('bookings holds no plain bookings.view anywhere; manage vs. view-own splits exactly per the Phase 6 matrix', function (string $role, bool $manage, bool $viewOwn) {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('bookings.manage'))->toBe($manage)
        ->and($user->can('bookings.view-own'))->toBe($viewOwn)
        ->and($user->can('bookings.view'))->toBeFalse();
})->with([
    'super_admin: manage' => ['super_admin', true, false],
    'ecommerce: none' => ['ecommerce', false, false],
    'operations: manage' => ['operations', true, false],
    'customer_support: manage' => ['customer_support', true, false],
    'technician: view-own only' => ['technician', false, true],
    'fleet: manage' => ['fleet', true, false],
]);

test('reporting.view matches the Phase 6 matrix and reporting.manage is never assigned to any role', function (string $role, bool $view) {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('reporting.view'))->toBe($view)
        ->and($user->can('reporting.manage'))->toBeFalse();
})->with([
    'super_admin' => ['super_admin', true],
    'ecommerce' => ['ecommerce', true],
    'operations' => ['operations', true],
    'customer_support' => ['customer_support', false],
    'technician' => ['technician', false],
    'fleet' => ['fleet', true],
]);

test('audit-log.view matches the Phase 6 matrix exactly (super_admin/operations/customer_support only)', function (string $role, bool $expected) {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('audit-log.view'))->toBe($expected);
})->with([
    'super_admin' => ['super_admin', true],
    'ecommerce' => ['ecommerce', false],
    'operations' => ['operations', true],
    'customer_support' => ['customer_support', true],
    'technician' => ['technician', false],
    'fleet' => ['fleet', false],
]);
