import { Link } from '@inertiajs/react';
import {
    BarChart3,
    CalendarClock,
    Car,
    FileText,
    LayoutGrid,
    MapPin,
    Package,
    ScrollText,
    ShieldCheck,
    ShoppingCart,
    Star,
    Tag,
    Users as UsersIcon,
    Warehouse,
} from 'lucide-react';
import AuditLogController from '@/actions/App/Http/Controllers/Admin/AuditLogController';
import DispatchBoardController from '@/actions/App/Http/Controllers/Admin/Bookings/DispatchBoardController';
import ContentPageController from '@/actions/App/Http/Controllers/Admin/Content/ContentPageController';
import CustomerController from '@/actions/App/Http/Controllers/Admin/Customers/CustomerController';
import OrderController from '@/actions/App/Http/Controllers/Admin/Orders/OrderController';
import BrandController from '@/actions/App/Http/Controllers/Admin/Products/BrandController';
import PromotionController from '@/actions/App/Http/Controllers/Admin/Promotions/PromotionController';
import ReportingController from '@/actions/App/Http/Controllers/Admin/Reporting/ReportingController';
import ReviewController from '@/actions/App/Http/Controllers/Admin/Reviews/ReviewController';
import StateController from '@/actions/App/Http/Controllers/Admin/Locations/StateController';
import StockLocationController from '@/actions/App/Http/Controllers/Admin/Inventory/StockLocationController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import VehicleController from '@/actions/App/Http/Controllers/Admin/Vehicles/VehicleController';
import AppLogo from '@/components/app-logo';
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
 * The 10 admin modules from the permission matrix (docs/architecture/07),
 * plus the standalone `audit-log.view` entry (not one of the 10 tiered
 * modules — see `RolesAndPermissionsSeeder::STANDALONE_PERMISSIONS_BY_ROLE`).
 * `href: null` = no real page yet (later phases); renders as a disabled
 * placeholder rather than a link. Each module's permission list is
 * "view or manage" (manage implies view) so the item shows for anyone with
 * at least read access — row-level scoping (e.g. the Technician's
 * `bookings.view-own`) still happens server-side per Policy, this list only
 * decides whether the nav entry appears at all.
 *
 * **Verified 2026-09-23 against `RolesAndPermissionsSeeder.php` directly**
 * (Phase 6 RBAC hardening pass — this project has hit permission-slug-vs-
 * seeded-name drift as a recurring bug class): every module's slug below is
 * a single word matching its display name and IS the real seeded name,
 * except "Roles & Users" (`roles-users.manage`, the one compound slug,
 * pinned by project-manager in an earlier phase) and "Bookings" (see that
 * entry's own comment — `bookings.view` is NOT a real seeded permission).
 */
const operationsNavItems: PermissionNavItem[] = [
    {
        title: 'Orders',
        href: OrderController.index().url,
        icon: ShoppingCart,
        permissions: ['orders.view', 'orders.manage'],
    },
    {
        title: 'Bookings',
        href: DispatchBoardController.index().url,
        icon: CalendarClock,
        // No plain `bookings.view` here — verified against
        // `RolesAndPermissionsSeeder`: nobody holds it (Operations/CS/Fleet/
        // Super Admin hold `bookings.manage`, a superset; only Technician
        // holds the separate `bookings.view-own`). Removed 2026-09-23 (Phase
        // 6 RBAC hardening pass) — it was a dead string in this `anyOf`
        // check, harmless since `bookings.manage`/`bookings.view-own` already
        // cover every real holder, but exactly the kind of unverified
        // literal this pass exists to catch.
        permissions: ['bookings.manage', 'bookings.view-own'],
    },
    {
        title: 'Customers',
        href: CustomerController.index().url,
        icon: UsersIcon,
        permissions: ['customers.view', 'customers.manage'],
    },
    {
        title: 'Inventory',
        href: StockLocationController.index().url,
        icon: Warehouse,
        permissions: ['inventory.view', 'inventory.manage'],
    },
    {
        title: 'Locations',
        href: StateController.index().url,
        icon: MapPin,
        permissions: ['locations.view', 'locations.manage'],
    },
    {
        title: 'Reporting',
        href: ReportingController.show('sales').url,
        icon: BarChart3,
        permissions: ['reporting.view'],
    },
];

const catalogueNavItems: PermissionNavItem[] = [
    {
        title: 'Products',
        href: BrandController.index().url,
        icon: Package,
        permissions: ['products.view', 'products.manage'],
    },
    {
        title: 'Vehicles',
        href: VehicleController.index().url,
        icon: Car,
        permissions: ['vehicles.view', 'vehicles.manage'],
    },
    {
        title: 'Promotions',
        href: PromotionController.index().url,
        icon: Tag,
        // **Updated 2026-09-23 (Phase 6 RBAC hardening pass):** now
        // `promotions.view` OR `promotions.manage`, not `.manage` alone.
        // Previously `.manage`-only was deliberate — every Promotions
        // screen's `index` route was ALSO gated `.manage` only, so a
        // `.view`-only visitor had no screen here to land on. backend-agent
        // fixed that route gating today (see `routes/admin.php`'s
        // Promotions section) so campaigns/price-guarantee-claims/price-
        // rules are now real, reachable, read-only screens for a
        // `.view`-only Operations/Customer Support user (create/edit/
        // delete controls stay hidden via each screen's own
        // `<Can permission="promotions.manage">`, e.g.
        // `PromotionsCampaignsIndex`) — this nav entry was still gating on
        // the old, narrower permission until this fix.
        permissions: ['promotions.view', 'promotions.manage'],
    },
];

const contentNavItems: PermissionNavItem[] = [
    {
        title: 'Content',
        href: ContentPageController.index().url,
        icon: FileText,
        permissions: ['content.view', 'content.manage'],
    },
    {
        title: 'Reviews',
        href: ReviewController.index().url,
        icon: Star,
        // Phase 8 — same `content.view`/`content.manage` pair as Content
        // above (no new permission; verified against `routes/admin.php`'s
        // Reviews group and `ReviewUpdateRequest`).
        permissions: ['content.view', 'content.manage'],
    },
];

const administrationNavItems: PermissionNavItem[] = [
    {
        title: 'Roles & Users',
        href: UserController.index().url,
        icon: ShieldCheck,
        permissions: ['roles-users.manage'],
    },
    {
        title: 'Audit Log',
        href: AuditLogController.index().url,
        icon: ScrollText,
        // Standalone permission, not a `{module}.view`/`.manage` pair — see
        // `RolesAndPermissionsSeeder::STANDALONE_PERMISSIONS_BY_ROLE`:
        // super_admin/operations/customer_support only, deliberately not
        // ecommerce/fleet/technician.
        permissions: ['audit-log.view'],
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
                <NavModules title="Operations" items={operationsNavItems} />
                <NavModules title="Catalogue" items={catalogueNavItems} />
                <NavModules
                    title="Content & Reputation"
                    items={contentNavItems}
                />
                <NavModules
                    title="Administration"
                    items={administrationNavItems}
                />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
