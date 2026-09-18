<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\RoleAssignmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('syncRoles assigns the role and writes one atomic audit log row', function () {
    $actor = User::factory()->create();
    $staff = User::factory()->create();

    (new RoleAssignmentService)->syncRoles($staff, ['operations'], $actor);

    expect($staff->fresh()->getRoleNames()->all())->toBe(['operations']);

    $log = AuditLog::sole();

    expect($log->auditable_type)->toBe(User::class)
        ->and($log->auditable_id)->toBe($staff->id)
        ->and($log->action)->toBe('roles.synced')
        ->and($log->actor_id)->toBe($actor->id)
        ->and($log->before)->toBe([])
        ->and($log->after)->toBe(['operations']);
});

test('syncRoles records the prior role set as before on a re-sync', function () {
    $staff = User::factory()->create();
    (new RoleAssignmentService)->syncRoles($staff, ['ecommerce']);

    (new RoleAssignmentService)->syncRoles($staff, ['fleet']);

    $log = AuditLog::latest('id')->first();

    expect($log->before)->toBe(['ecommerce'])
        ->and($log->after)->toBe(['fleet']);
});

test('syncRolePermissions updates a role bundle and writes an audit log row against the role', function () {
    $role = Role::findByName('fleet', 'web');
    $actor = User::factory()->create();

    (new RoleAssignmentService)->syncRolePermissions($role, ['locations.view'], $actor);

    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['locations.view']);

    $log = AuditLog::sole();

    expect($log->auditable_type)->toBe(Role::class)
        ->and($log->auditable_id)->toBe($role->id)
        ->and($log->actor_id)->toBe($actor->id);
});
