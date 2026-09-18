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
