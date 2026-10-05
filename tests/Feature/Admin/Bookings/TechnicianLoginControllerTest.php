<?php

use App\Models\AuditLog;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/**
 * Covers App\Http\Controllers\Admin\Bookings\TechnicianLoginController. Per
 * docs/architecture/07-admin-auth-permissions.md §5 this is deliberately
 * gated on `roles-users.manage` (Super Admin only), not `bookings.manage` —
 * the one place in the Bookings module where `bookings.manage` is
 * deliberately *not* sufficient, so that's asserted explicitly below
 * alongside the provisioning behavior itself (mirrors StaffUserInviteTest's
 * coverage of the near-identical UserController::store() flow).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a super_admin can create a login for a technician, who is created without a usable password and sent a reset link', function () {
    Notification::fake();

    $admin = actingSuperAdmin();
    $technician = Technician::factory()->create(['name' => 'Jordan Fitter', 'user_id' => null]);

    $response = $this->actingAs($admin)->post(route('admin.bookings.technicians.login.store', $technician), [
        'email' => 'jordan.fitter@example.com',
    ]);

    $response->assertRedirect();
    $response->assertInertiaFlash('toast.type', 'success');

    $newUser = User::where('email', 'jordan.fitter@example.com')->sole();

    expect($newUser->name)->toBe('Jordan Fitter')
        ->and($newUser->getRoleNames()->all())->toBe(['technician'])
        ->and($newUser->two_factor_confirmed_at)->toBeNull();

    expect($technician->refresh()->user_id)->toBe($newUser->id);

    Notification::assertSentTo($newUser, ResetPassword::class);

    $log = AuditLog::where('auditable_id', $newUser->id)->where('action', 'roles.synced')->sole();
    expect($log->actor_id)->toBe($admin->id)
        ->and($log->after)->toBe(['technician']);
});

test('creating a login for a technician that already has one is rejected with a 409 and no second user is created', function () {
    $admin = actingSuperAdmin();
    $existingLoginUser = User::factory()->withTwoFactor()->create();
    $technician = Technician::factory()->create(['user_id' => $existingLoginUser->id]);

    $response = $this->actingAs($admin)->post(route('admin.bookings.technicians.login.store', $technician), [
        'email' => 'second-login@example.com',
    ]);

    $response->assertStatus(409);
    $this->assertDatabaseMissing('users', ['email' => 'second-login@example.com']);
    expect($technician->refresh()->user_id)->toBe($existingLoginUser->id);
});

test('a bookings.manage-only user (operations) is forbidden from provisioning a technician login', function () {
    $operations = User::factory()->withTwoFactor()->create();
    $operations->assignRole('operations');
    $technician = Technician::factory()->create(['user_id' => null]);

    $response = $this->actingAs($operations)->post(route('admin.bookings.technicians.login.store', $technician), [
        'email' => 'new-login@example.com',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'new-login@example.com']);
    expect($technician->refresh()->user_id)->toBeNull();
});

test('the email must be unique among users', function () {
    $admin = actingSuperAdmin();
    $taken = User::factory()->withTwoFactor()->create(['email' => 'taken@example.com']);
    $technician = Technician::factory()->create(['user_id' => null]);

    $response = $this->actingAs($admin)->post(route('admin.bookings.technicians.login.store', $technician), [
        'email' => 'taken@example.com',
    ]);

    $response->assertSessionHasErrors('email');
});
