<?php

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Regression coverage for a page that was completely unreachable despite
 * `StoreStaffUserRequest`/the `admin.users.store` route being fully
 * permission-gated and tested: the index page had no `GET` route at all, and
 * separately the React page gated its UI behind a permission slug
 * (`roles.manage`) that no seeded role actually holds (the real slug is
 * `roles-users.manage`). Every prior test in this file exercised the `POST`
 * action directly via `route('admin.users.store')`, so neither bug was ever
 * caught. Asserting a real `GET` navigation — not just the form submission —
 * is what would have caught this class of bug.
 */
test('a super_admin can navigate to the staff/users index page and sees the invite UI and staff list', function () {
    $admin = actingSuperAdmin();
    $admin->forceFill(['name' => 'AAA Admin'])->save();

    $existing = User::factory()->withTwoFactor()->create(['name' => 'ZZZ Existing Op']);
    $existing->assignRole('operations');

    // Names are picked to sort deterministically ('AAA' before 'ZZZ') so the
    // `orderBy('name')` in `UserController::index()` gives a stable order to
    // assert against.
    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/index')
            ->has('staff', 2)
            ->where('staff.0.name', 'AAA Admin')
            ->where('staff.0.roles', ['super_admin'])
            ->where('staff.1.name', 'ZZZ Existing Op')
            ->where('staff.1.roles', ['operations']),
        );
});

test('a staff user without roles-users.manage is forbidden from both the GET and POST users routes', function () {
    $ecommerce = User::factory()->withTwoFactor()->create();
    $ecommerce->assignRole('ecommerce');

    $this->actingAs($ecommerce)
        ->get(route('admin.users.index'))
        ->assertForbidden();

    $this->actingAs($ecommerce)
        ->post(route('admin.users.store'), [
            'name' => 'New Hire',
            'email' => 'new-hire@example.com',
            'role' => 'operations',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'new-hire@example.com']);
});

test('a super_admin can invite a staff user, who is created without a usable password and sent a reset link', function () {
    Notification::fake();

    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'New Hire',
        'email' => 'new-hire@example.com',
        'role' => 'operations',
    ]);

    $response->assertRedirect();

    $newUser = User::where('email', 'new-hire@example.com')->sole();

    expect($newUser->getRoleNames()->all())->toBe(['operations'])
        ->and($newUser->two_factor_confirmed_at)->toBeNull();

    Notification::assertSentTo($newUser, ResetPassword::class);

    $log = AuditLog::where('auditable_id', $newUser->id)->sole();
    expect($log->action)->toBe('roles.synced')
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->after)->toBe(['operations']);
});

test('a staff user without roles-users.manage cannot invite staff', function () {
    $ecommerce = User::factory()->withTwoFactor()->create();
    $ecommerce->assignRole('ecommerce');

    $response = $this->actingAs($ecommerce)->post(route('admin.users.store'), [
        'name' => 'New Hire',
        'email' => 'new-hire@example.com',
        'role' => 'operations',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'new-hire@example.com']);
});

test('an unknown role name is rejected', function () {
    $admin = actingSuperAdmin();

    $response = $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'New Hire',
        'email' => 'new-hire@example.com',
        'role' => 'not-a-real-role',
    ]);

    $response->assertSessionHasErrors('role');
});
