import { Link } from '@inertiajs/react';
import {
    BarChart3,
    BookOpen,
    CalendarClock,
    Car,
    FileText,
    FolderGit2,
    LayoutGrid,
    MapPin,
    Package,
    ShieldCheck,
    ShoppingCart,
    Tag,
    Users as UsersIcon,
    Warehouse,
} from 'lucide-react';
import BrandController from '@/actions/App/Http/Controllers/Admin/Products/BrandController';
import StateController from '@/actions/App/Http/Controllers/Admin/Locations/StateController';
import StockLocationController from '@/actions/App/Http/Controllers/Admin/Inventory/StockLocationController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import VehicleController from '@/actions/App/Http/Controllers/Admin/Vehicles/VehicleController';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavModules } from '@/components/nav-modules';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem, PermissionNavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

/**
 * The 10 admin modules from the permission matrix (docs/architecture/07).
 * `href: null` = no real page yet (later phases); renders as a disabled
 * placeholder rather than a link. Each module's permission list is
 * "view or manage" (manage implies view) so the item shows for anyone with
 * at least read access — row-level scoping (e.g. the Technician's
 * `bookings.view-own`) still happens server-side per Policy, this list only
 * decides whether the nav entry appears at all.
 *
 * "Roles & Users" permission slug is `roles-users.manage` — pinned by
 * project-manager to match backend-agent's seeder (every other module's
 * slug is a single word matching its display name; this is the one compound
 * one). The other 9 modules' slugs are still inferred from the doc's
 * `{module}.manage` pattern and unconfirmed against the seeder.
 */
const moduleNavItems: PermissionNavItem[] = [
    {
        title: 'Products',
        href: BrandController.index().url,
        icon: Package,
        permissions: ['products.view', 'products.manage'],
    },
    {
        title: 'Inventory',
        href: StockLocationController.index().url,
        icon: Warehouse,
        permissions: ['inventory.view', 'inventory.manage'],
    },
    {
        title: 'Orders',
        href: null,
        icon: ShoppingCart,
        permissions: ['orders.view', 'orders.manage'],
    },
    {
        title: 'Bookings',
        href: null,
        icon: CalendarClock,
        permissions: ['bookings.view', 'bookings.manage', 'bookings.view-own'],
    },
    {
        title: 'Customers',
        href: null,
        icon: UsersIcon,
        permissions: ['customers.view', 'customers.manage'],
    },
    {
        title: 'Locations',
        href: StateController.index().url,
        icon: MapPin,
        permissions: ['locations.view', 'locations.manage'],
    },
    {
        title: 'Promotions',
        href: null,
        icon: Tag,
        permissions: ['promotions.view', 'promotions.manage'],
    },
    {
        title: 'Content',
        href: null,
        icon: FileText,
        permissions: ['content.view', 'content.manage'],
    },
    {
        title: 'Reporting',
        href: null,
        icon: BarChart3,
        permissions: ['reporting.view'],
    },
    {
        title: 'Roles & Users',
        href: UserController.index().url,
        icon: ShieldCheck,
        permissions: ['roles-users.manage'],
    },
    {
        title: 'Vehicles',
        href: VehicleController.index().url,
        icon: Car,
        permissions: ['vehicles.view', 'vehicles.manage'],
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()}>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <NavModules items={moduleNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
