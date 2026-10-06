import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { usePermissions } from '@/hooks/use-permissions';
import type { PermissionNavItem } from '@/types';

/**
 * Role-aware nav skeleton for the 10 admin modules. Items are hidden
 * entirely when the user holds none of the listed permissions; this is a
 * UX convenience, not the access boundary (see `usePermissions()`).
 *
 * Items without a real page yet (`href: null`) render as a disabled
 * placeholder — most modules land in later phases, this is a skeleton
 * for the eventual full nav, not full nav wiring.
 */
export function NavModules({
    title,
    items,
}: {
    title: string;
    items: PermissionNavItem[];
}) {
    const { hasAnyPermission } = usePermissions();
    const { isCurrentUrl } = useCurrentUrl();

    const visibleItems = items.filter((item) =>
        hasAnyPermission(item.permissions),
    );

    if (visibleItems.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{title}</SidebarGroupLabel>
            <SidebarMenu>
                {visibleItems.map((item) =>
                    item.href ? (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={isCurrentUrl(item.href)}
                                tooltip={{ children: item.title }}
                            >
                                <Link href={item.href}>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    ) : (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                disabled
                                tooltip={{
                                    children: `${item.title} — coming soon`,
                                }}
                            >
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                                <Badge
                                    variant="secondary"
                                    className="ml-auto text-[10px]"
                                >
                                    Soon
                                </Badge>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    ),
                )}
            </SidebarMenu>
        </SidebarGroup>
    );
}
