import type { ReactNode } from 'react';
import { usePermissions } from '@/hooks/use-permissions';

export type CanProps = {
    /** Render children if the user holds this single permission. */
    permission?: string;
    /** Render children if the user holds any one of these permissions. */
    anyOf?: string[];
    /** Render children if the user holds this role. Prefer `permission`/`anyOf` — role checks couple UI to today's 6 roles instead of the permission bundle behind them. */
    role?: string;
    /** Rendered when the check fails. Defaults to nothing. */
    fallback?: ReactNode;
    children: ReactNode;
};

/**
 * JSX sugar over `usePermissions()` for call sites that prefer conditional
 * rendering to an `if`. Always delegates to `usePermissions()` — do not add
 * a second component that independently re-parses `usePage().props.auth`
 * (see that hook's doc comment for why).
 *
 * SECURITY NOTE: UX only, never the security boundary — see `usePermissions()`.
 * Hiding a block with `<Can>` does not protect the route it links to; the
 * server must independently deny it via middleware + Policy classes.
 */
export function Can({
    permission,
    anyOf,
    role,
    fallback = null,
    children,
}: CanProps) {
    const { hasPermission, hasAnyPermission, hasRole } = usePermissions();

    const allowed =
        (permission ? hasPermission(permission) : true) &&
        (anyOf ? hasAnyPermission(anyOf) : true) &&
        (role ? hasRole(role) : true);

    return allowed ? <>{children}</> : <>{fallback}</>;
}
