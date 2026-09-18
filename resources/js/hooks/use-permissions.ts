import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type { Auth } from '@/types';

export type UsePermissionsReturn = {
    /** All permission names the current user holds, e.g. `['orders.manage']`. */
    permissions: string[];
    /** All role names the current user holds, e.g. `['operations']`. */
    roles: string[];
    hasPermission: (permission: string) => boolean;
    hasAnyPermission: (permissions: string[]) => boolean;
    hasRole: (role: string) => boolean;
};

/**
 * The one and only place that reads `usePage().props.auth`.
 *
 * `<Can>` and any other permission-aware UI must call this hook rather than
 * re-parsing `usePage().props.auth` independently — two parallel readers of
 * the same prop are exactly how they silently drift apart over time (one
 * gets updated for a new check, the other doesn't).
 *
 * SECURITY NOTE: this is a UX convenience only — it hides/shows nav items and
 * buttons a user's role can't use. It is NEVER the security boundary. Every
 * server route independently enforces access via spatie `role:`/`permission:`
 * middleware and Policy classes. A user who edits this payload in devtools or
 * hits a route directly must hit the exact same server-side denial that a
 * hidden button here would have prevented.
 */
export function usePermissions(): UsePermissionsReturn {
    const { props } = usePage<{ auth: Auth }>();

    const permissions = props.auth?.permissions ?? [];
    const roles = props.auth?.roles ?? [];

    const permissionSet = useMemo(() => new Set(permissions), [permissions]);
    const roleSet = useMemo(() => new Set(roles), [roles]);

    return useMemo(
        () => ({
            permissions,
            roles,
            hasPermission: (permission: string) =>
                permissionSet.has(permission),
            hasAnyPermission: (names: string[]) =>
                names.some((permission) => permissionSet.has(permission)),
            hasRole: (role: string) => roleSet.has(role),
        }),
        [permissions, roles, permissionSet, roleSet],
    );
}
