import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

/**
 * A nav entry gated by permission, for modules that don't necessarily have a
 * page yet. `href: null` renders the item as a disabled placeholder ("coming
 * soon") instead of a link — used for modules that land in a later phase.
 */
export type PermissionNavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']> | null;
    icon?: LucideIcon | null;
    /** Shown if the user holds any one of these permissions (spatie permission names). */
    permissions: string[];
};
