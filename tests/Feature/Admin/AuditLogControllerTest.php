<?php

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Covers App\Http\Controllers\Admin\AuditLogController — gated on the
 * standalone `audit-log.view` permission (verified against
 * RolesAndPermissionsSeeder's `STANDALONE_PERMISSIONS_BY_ROLE`: super_admin,
 * operations, and customer_support only — deliberately NOT ecommerce/fleet/
 * technician). The underlying query/filter logic belongs to
 * `AuditLogQueryService` and is covered by `AuditLogQueryServiceTest.php` —
 * this file only exercises the controller's own concerns: route/permission
 * wiring and the `AuditLogFilters` construction from request input.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function auditLogViewer(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('operations'); // audit-log.view

    return $user;
}

test('an audit-log.view user can view the audit log index', function () {
    $viewer = auditLogViewer();
    AuditLog::factory()->create();

    $response = $this->actingAs($viewer)->get(route('admin.audit-log.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('audit-log/index')
        ->has('logs')
        ->has('actors')
        ->has('auditableTypes'));
});

test('a user without audit-log.view is forbidden', function () {
    $outsider = User::factory()->withTwoFactor()->create();
    $outsider->assignRole('ecommerce'); // holds reporting.view but not the standalone audit-log.view

    $this->actingAs($outsider)->get(route('admin.audit-log.index'))->assertForbidden();
});

test('the actor_id=system sentinel filters to actor_id IS NULL rows only', function () {
    $viewer = auditLogViewer();
    $systemLog = AuditLog::factory()->create(['actor_id' => null]);
    AuditLog::factory()->create(['actor_id' => $viewer->id]);

    $response = $this->actingAs($viewer)->get(route('admin.audit-log.index', ['actor_id' => 'system']));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('audit-log/index')
        ->where('logs.total', 1)
        ->where('logs.data.0.id', $systemLog->id));
});

test('a numeric actor_id filters to that specific user', function () {
    $viewer = auditLogViewer();
    $other = User::factory()->create();
    $matching = AuditLog::factory()->create(['actor_id' => $other->id]);
    AuditLog::factory()->create(['actor_id' => $viewer->id]);

    $response = $this->actingAs($viewer)->get(route('admin.audit-log.index', ['actor_id' => (string) $other->id]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('logs.total', 1)
        ->where('logs.data.0.id', $matching->id));
});

test('the action filter is a contains-match, not an exact-match', function () {
    $viewer = auditLogViewer();
    $matching = AuditLog::factory()->create(['action' => 'bookings.cancelled']);
    AuditLog::factory()->create(['action' => 'orders.refunded']);

    $response = $this->actingAs($viewer)->get(route('admin.audit-log.index', ['action' => 'cancel']));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('logs.total', 1)
        ->where('logs.data.0.id', $matching->id));
});

/**
 * Gap closed 2026-09-24: `RoleAssignmentService::syncRolePermissions()`
 * writes `auditable_type = Role::class`, distinct from `syncRoles()`'s
 * `User::class` — both existed as real rows before this fix, but nothing
 * exercised the `auditable_type` filter through the controller's own
 * request-input parsing (only `AuditLogQueryServiceTest.php` covered the
 * query layer directly).
 */
test('the auditable_type filter returns only rows of that exact type, e.g. Role::class distinct from User::class', function () {
    $viewer = auditLogViewer();
    $roleLog = AuditLog::factory()->create(['auditable_type' => Role::class, 'auditable_id' => 1, 'action' => 'roles.permissions_synced']);
    AuditLog::factory()->create(['auditable_type' => User::class, 'auditable_id' => 1, 'action' => 'users.roles_synced']);

    $response = $this->actingAs($viewer)->get(route('admin.audit-log.index', ['auditable_type' => Role::class]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('logs.total', 1)
        ->where('logs.data.0.id', $roleLog->id));
});

/**
 * Gap closed 2026-09-24: the `from`/`to` query-string values are parsed by
 * the controller itself (`AuditLogController::parseDate()`, including the
 * `to`-side `->endOfDay()` inclusivity) before ever reaching
 * `AuditLogQueryService` — `AuditLogQueryServiceTest.php`'s date-range
 * coverage exercises the service with already-parsed `CarbonImmutable`
 * values, not this controller-side parsing step.
 */
test('the from/to date range filters to rows created within that window', function () {
    $viewer = auditLogViewer();
    $inRange = AuditLog::factory()->create(['created_at' => now()->subDays(2)]);
    $tooOld = AuditLog::factory()->create(['created_at' => now()->subMonth()]);
    $tooNew = AuditLog::factory()->create(['created_at' => now()->addMonth()]);

    $response = $this->actingAs($viewer)->get(route('admin.audit-log.index', [
        'from' => now()->subWeek()->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('logs.total', 1)
        ->where('logs.data.0.id', $inRange->id));

    expect(AuditLog::query()->whereIn('id', [$tooOld->id, $tooNew->id])->count())->toBe(2); // sanity: they exist, just excluded
});
