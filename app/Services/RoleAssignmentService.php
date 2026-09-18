<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Centralizes every role/permission mutation for staff (`User`) accounts so
 * every change writes exactly one atomic `AuditLog` row, rather than
 * scattering bare `HasRoles`/`HasPermissions` calls across controllers.
 */
class RoleAssignmentService
{
    /**
     * Sync a staff user's roles to exactly the given set, recording one
     * atomic `AuditLog` row (`action = 'roles.synced'`) for the change.
     *
     * @param  list<string>  $roleNames
     */
    public function syncRoles(User $user, array $roleNames, ?User $actor = null): User
    {
        $before = $user->getRoleNames()->values()->all();

        DB::transaction(function () use ($user, $roleNames, $before, $actor): void {
            $user->syncRoles($roleNames);

            AuditLog::create([
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'action' => 'roles.synced',
                'actor_id' => $actor?->id,
                'before' => $before,
                'after' => $user->getRoleNames()->values()->all(),
            ]);
        });

        return $user->refresh();
    }

    /**
     * Sync a role's own permission bundle to exactly the given set (e.g.
     * super_admin adjusting what the "operations" role can do), recording
     * one atomic `AuditLog` row (`action = 'roles.synced'`,
     * `auditable_type = Role::class`) for the change.
     *
     * @param  list<string>  $permissionNames
     */
    public function syncRolePermissions(Role $role, array $permissionNames, ?User $actor = null): Role
    {
        $before = $role->permissions()->pluck('name')->values()->all();

        DB::transaction(function () use ($role, $permissionNames, $before, $actor): void {
            $role->syncPermissions($permissionNames);

            AuditLog::create([
                'auditable_type' => Role::class,
                'auditable_id' => $role->id,
                'action' => 'roles.synced',
                'actor_id' => $actor?->id,
                'before' => $before,
                'after' => $role->permissions()->pluck('name')->values()->all(),
            ]);
        });

        return $role->refresh();
    }
}
