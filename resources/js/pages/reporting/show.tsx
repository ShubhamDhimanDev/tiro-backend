import { Head, router } from '@inertiajs/react';
import ReportingController from '@/actions/App/Http/Controllers/Admin/Reporting/ReportingController';
import { StatTile } from '@/components/stat-tile';
import { StatusBreakdownBars } from '@/components/status-breakdown-bars';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    BOOKING_STATUS_LABELS,
    ORDER_STATUS_LABELS,
    bookingStatusBadgeVariant,
    orderStatusBadgeVariant,
} from '@/lib/enums';
import { formatCents } from '@/lib/money';
import type {
    BookingsReport,
    CancellationReport,
    ConversionReport,
    ProductPerformanceRow,
    ReportingDashboard,
    ReportingFilters,
    SalesReport,
} from '@/types/reporting';
import type { BreadcrumbItem } from '@/types';

const ALL_ZONES = 'all';

type ServiceZoneOption = { id: number; name: string };

type DashboardData =
    | SalesReport
    | BookingsReport
    | ConversionReport
    | CancellationReport
    | ProductPerformanceRow[];

function ReportingFilterBar({
    dashboard,
    filters,
    serviceZones,
}: {
    dashboard: ReportingDashboard;
    filters: ReportingFilters;
    serviceZones: ServiceZoneOption[];
}) {
    const apply = (next: Partial<Record<string, string | number | null>>) => {
        router.get(
            ReportingController.show(dashboard).url,
            {
                from: filters.from,
                to: filters.to,
                service_zone_id: filters.service_zone_id,
                limit: filters.limit,
                ...next,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <Card>
            <CardContent className="flex flex-wrap items-end gap-4 pt-6">
                <div className="grid gap-2">
                    <Label htmlFor="filter-from">From</Label>
                    <Input
                        id="filter-from"
                        type="date"
                        className="w-40"
                        value={filters.from}
                        onChange={(e) => apply({ from: e.target.value })}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="filter-to">To</Label>
                    <Input
                        id="filter-to"
                        type="date"
                        className="w-40"
                        value={filters.to}
                        onChange={(e) => apply({ to: e.target.value })}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="filter-zone">Service zone</Label>
                    <Select
                        value={
                            filters.service_zone_id
                                ? String(filters.service_zone_id)
                                : ALL_ZONES
                        }
                        onValueChange={(v) =>
                            apply({
                                service_zone_id: v === ALL_ZONES ? null : v,
                            })
                        }
                    >
                        <SelectTrigger id="filter-zone" className="w-56">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL_ZONES}>All zones</SelectItem>
                            {serviceZones.map((zone) => (
                                <SelectItem
                                    key={zone.id}
                                    value={String(zone.id)}
                                >
                                    {zone.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {dashboard === 'product-performance' && (
                    <div className="grid gap-2">
                        <Label htmlFor="filter-limit">Show top</Label>
                        <Select
                            value={String(filters.limit)}
                            onValueChange={(v) => apply({ limit: v })}
                        >
                            <SelectTrigger id="filter-limit" className="w-28">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[5, 10, 20, 50].map((n) => (
                                    <SelectItem key={n} value={String(n)}>
                                        {n}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() =>
                        router.get(ReportingController.show(dashboard).url)
                    }
                >
                    Reset to last 30 days
                </Button>
            </CardContent>
        </Card>
    );
}

function SalesDashboard({ data }: { data: SalesReport }) {
    return (
        <>
            <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <StatTile
                    label="Orders placed"
                    value={String(data.order_count)}
                />
                <StatTile
                    label="Paid orders"
                    value={String(data.paid_order_count)}
                />
                <StatTile
                    label="Gross revenue"
                    value={formatCents(data.gross_revenue)}
                    emphasis
                />
                <StatTile
                    label="Refunds"
                    value={formatCents(data.refunds_total)}
                />
                <StatTile
                    label="Net revenue"
                    value={formatCents(data.net_revenue)}
                    emphasis
                />
                <StatTile
                    label="Average order value"
                    value={formatCents(data.average_order_value)}
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Orders by status</CardTitle>
                </CardHeader>
                <CardContent>
                    <StatusBreakdownBars
                        data={data.orders_by_status}
                        labels={ORDER_STATUS_LABELS}
                        badgeVariant={(s) =>
                            orderStatusBadgeVariant(
                                s as keyof typeof ORDER_STATUS_LABELS,
                            )
                        }
                    />
                </CardContent>
            </Card>
        </>
    );
}

function BookingsDashboard({ data }: { data: BookingsReport }) {
    return (
        <>
            <div className="grid gap-4 sm:grid-cols-3">
                <StatTile
                    label="Bookings scheduled"
                    value={String(data.booking_count)}
                    emphasis
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Bookings by status</CardTitle>
                    <CardDescription>
                        Filtered on scheduled date, not creation date.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <StatusBreakdownBars
                        data={data.bookings_by_status}
                        labels={BOOKING_STATUS_LABELS}
                        badgeVariant={(s) =>
                            bookingStatusBadgeVariant(
                                s as keyof typeof BOOKING_STATUS_LABELS,
                            )
                        }
                    />
                </CardContent>
            </Card>
        </>
    );
}

function ConversionDashboard({ data }: { data: ConversionReport }) {
    return (
        <>
            <Card className="border-primary/30 bg-primary/5">
                <CardContent className="pt-6">
                    <p className="text-muted-foreground text-xs font-medium">
                        Hold → Order Conversion Rate
                    </p>
                    <p className="mt-1 text-4xl font-semibold tabular-nums">
                        {(data.conversion_rate * 100).toFixed(1)}%
                    </p>
                    <p className="text-muted-foreground mt-2 text-xs">
                        The share of booking holds <em>created</em> in this
                        range that reached a paid order. This is a hold→order
                        rate, not a true site-traffic funnel conversion rate —
                        this project has no session/pageview tracking to compute
                        that from.
                    </p>
                </CardContent>
            </Card>

            <div className="grid gap-4 sm:grid-cols-4">
                <StatTile
                    label="Total holds"
                    value={String(data.total_holds)}
                />
                <StatTile
                    label="Converted to order"
                    value={String(data.converted_count)}
                />
                <StatTile label="Expired" value={String(data.expired_count)} />
                <StatTile
                    label="Cancelled"
                    value={String(data.cancelled_count)}
                />
            </div>
        </>
    );
}

function CancellationDashboard({ data }: { data: CancellationReport }) {
    const byInitiator: Record<string, number> = {
        staff: data.staff_initiated,
        customer: data.customer_initiated,
    };
    const labels: Record<string, string> = {
        staff: 'Staff-initiated',
        customer: 'Customer-initiated',
    };

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-4">
                <StatTile
                    label="Total cancelled"
                    value={String(data.total_cancelled)}
                    emphasis
                />
                <StatTile
                    label="Staff-initiated"
                    value={String(data.staff_initiated)}
                />
                <StatTile
                    label="Customer-initiated"
                    value={String(data.customer_initiated)}
                />
                <StatTile
                    label="Expired (never cancelled)"
                    value={String(data.expired)}
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Cancellation initiator split</CardTitle>
                    <CardDescription>
                        Staff-initiated is detected via a matching
                        `bookings.cancelled` audit-log row; everything else
                        cancelled is treated as customer-initiated. Expired
                        holds are their own separate bucket, not counted as a
                        cancellation.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <StatusBreakdownBars
                        data={byInitiator}
                        labels={labels}
                        badgeVariant={(s) =>
                            s === 'staff' ? 'default' : 'secondary'
                        }
                    />
                </CardContent>
            </Card>
        </>
    );
}

function ProductPerformanceDashboard({
    rows,
}: {
    rows: ProductPerformanceRow[];
}) {
    const maxRevenue = Math.max(1, ...rows.map((r) => r.revenue));

    return (
        <Card>
            <CardHeader>
                <CardTitle>Top-selling variants</CardTitle>
                <CardDescription>
                    Ranked by revenue, orders placed in this range only.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No revenue in this range.
                    </p>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="py-2 font-medium">Model</th>
                                <th className="py-2 font-medium">SKU</th>
                                <th className="py-2 font-medium">Units sold</th>
                                <th className="py-2 font-medium">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr
                                    key={row.tyre_variant_id}
                                    className="border-b last:border-0"
                                >
                                    <td className="py-2 font-medium">
                                        {row.tyre_model_name}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {row.sku}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {row.units_sold}
                                    </td>
                                    <td className="py-2">
                                        <div className="flex items-center gap-2">
                                            <span className="w-20 shrink-0 tabular-nums">
                                                {formatCents(row.revenue)}
                                            </span>
                                            <div className="bg-muted h-2 flex-1 overflow-hidden rounded-full">
                                                <div
                                                    className="bg-primary h-full rounded-full"
                                                    style={{
                                                        width: `${Math.round((row.revenue / maxRevenue) * 100)}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </CardContent>
        </Card>
    );
}

const DASHBOARD_TITLES: Record<ReportingDashboard, string> = {
    sales: 'Sales',
    bookings: 'Bookings',
    conversion: 'Hold → Order Conversion',
    cancellation: 'Cancellations',
    'product-performance': 'Product Performance',
};

export default function ReportingShow({
    dashboard,
    data,
    filters,
    serviceZones,
}: {
    dashboard: ReportingDashboard;
    data: DashboardData;
    filters: ReportingFilters;
    serviceZones: ServiceZoneOption[];
}) {
    return (
        <>
            <Head title={`Reporting — ${DASHBOARD_TITLES[dashboard]}`} />

            <div className="space-y-6">
                <ReportingFilterBar
                    dashboard={dashboard}
                    filters={filters}
                    serviceZones={serviceZones}
                />

                {dashboard === 'sales' && (
                    <SalesDashboard data={data as SalesReport} />
                )}
                {dashboard === 'bookings' && (
                    <BookingsDashboard data={data as BookingsReport} />
                )}
                {dashboard === 'conversion' && (
                    <ConversionDashboard data={data as ConversionReport} />
                )}
                {dashboard === 'cancellation' && (
                    <CancellationDashboard data={data as CancellationReport} />
                )}
                {dashboard === 'product-performance' && (
                    <ProductPerformanceDashboard
                        rows={data as ProductPerformanceRow[]}
                    />
                )}
            </div>
        </>
    );
}

ReportingShow.layout = {
    breadcrumbs: [
        { title: 'Reporting', href: ReportingController.show('sales').url },
    ] satisfies BreadcrumbItem[],
};
