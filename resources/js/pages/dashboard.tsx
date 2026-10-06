import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import DispatchBoardController from '@/actions/App/Http/Controllers/Admin/Bookings/DispatchBoardController';
import PriceGuaranteeClaimController from '@/actions/App/Http/Controllers/Admin/Promotions/PriceGuaranteeClaimController';
import ReportingController from '@/actions/App/Http/Controllers/Admin/Reporting/ReportingController';
import ReviewController from '@/actions/App/Http/Controllers/Admin/Reviews/ReviewController';
import StockLocationController from '@/actions/App/Http/Controllers/Admin/Inventory/StockLocationController';
import OrderController from '@/actions/App/Http/Controllers/Admin/Orders/OrderController';
import BrandController from '@/actions/App/Http/Controllers/Admin/Products/BrandController';
import PromotionController from '@/actions/App/Http/Controllers/Admin/Promotions/PromotionController';
import Heading from '@/components/heading';
import { StatTile } from '@/components/stat-tile';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate, formatDateTime } from '@/lib/date';
import { formatCents } from '@/lib/money';
import { dashboard } from '@/routes';

type Sales = {
    net_revenue: number;
    gross_revenue: number;
    refunds_total: number;
    order_count: number;
    paid_order_count: number;
    average_order_value: number;
    orders_by_status: Record<string, number>;
    daily: { date: string; orders: number; revenue: number }[];
};

type DashboardProps = {
    windowDays: number;
    sales: Sales | null;
    orders: {
        awaiting_payment: number;
        needs_refund: number;
        payment_failed: number;
        recent: {
            id: number;
            order_number: string;
            customer: string | null;
            status: string;
            grand_total: number;
            placed_at: string | null;
        }[];
    } | null;
    bookings: {
        today_count: number;
        next_seven_days: number;
        unassigned: number;
        today: {
            id: number;
            order_id: number | null;
            slot_start: string;
            slot_end: string;
            status: string;
            customer: string | null;
            technician: string | null;
            zone: string | null;
        }[];
    } | null;
    customers: { total: number; new_last_seven_days: number } | null;
    inventory: {
        low_stock_count: number;
        out_of_stock_count: number;
        low_stock: {
            id: number;
            location_id: number;
            location: string | null;
            model: string | null;
            size: string | null;
            available: number;
            reorder_point: number;
        }[];
    } | null;
    promotions: { active: number; pending_claims: number } | null;
    catalogue: {
        brands: number;
        models: number;
        variants: number;
        inactive_variants: number;
    } | null;
    reviews: {
        count: number;
        average_rating: number;
        hidden_count: number;
    } | null;
};

const label = (value: string) => value.replace(/_/g, ' ');

function statusVariant(status: string) {
    if (['completed', 'confirmed', 'active'].includes(status)) {
        return 'default' as const;
    }

    if (
        ['cancelled', 'payment_failed', 'refund_required', 'no_show'].includes(
            status,
        )
    ) {
        return 'destructive' as const;
    }

    return 'secondary' as const;
}

function Section({
    title,
    description,
    href,
    linkLabel,
    children,
}: {
    title: string;
    description?: string;
    href?: string;
    linkLabel?: string;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div className="space-y-1.5">
                    <CardTitle>{title}</CardTitle>
                    {description && (
                        <CardDescription>{description}</CardDescription>
                    )}
                </div>
                {href && (
                    <Link
                        href={href}
                        className="text-sm font-medium whitespace-nowrap hover:underline"
                    >
                        {linkLabel ?? 'View all'}
                    </Link>
                )}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground text-sm">{children}</p>;
}

function RevenueBars({ daily }: { daily: Sales['daily'] }) {
    const max = Math.max(...daily.map((d) => d.revenue), 1);

    return (
        <div>
            <div
                className="flex h-32 items-end gap-1"
                role="img"
                aria-label="Revenue per day, last 14 days"
            >
                {daily.map((day) => (
                    <div
                        key={day.date}
                        className="bg-primary/80 hover:bg-primary min-h-[2px] flex-1 rounded-t transition-colors"
                        style={{ height: `${(day.revenue / max) * 100}%` }}
                        title={`${formatDate(day.date)} — ${formatCents(day.revenue)} (${day.orders} order${day.orders === 1 ? '' : 's'})`}
                    />
                ))}
            </div>
            <div className="text-muted-foreground mt-1 flex justify-between text-xs">
                <span>{formatDate(daily[0]?.date)}</span>
                <span>{formatDate(daily[daily.length - 1]?.date)}</span>
            </div>
        </div>
    );
}

export default function Dashboard({
    windowDays,
    sales,
    orders,
    bookings,
    customers,
    inventory,
    promotions,
    catalogue,
    reviews,
}: DashboardProps) {
    const hasAnything = [
        sales,
        orders,
        bookings,
        customers,
        inventory,
        promotions,
        catalogue,
        reviews,
    ].some((block) => block !== null);

    const attention: { text: string; href: string; tone: 'warn' | 'bad' }[] =
        [];

    if (orders && orders.needs_refund > 0) {
        attention.push({
            text: `${orders.needs_refund} order${orders.needs_refund === 1 ? '' : 's'} need a refund`,
            href: OrderController.index().url,
            tone: 'bad',
        });
    }

    if (orders && orders.payment_failed > 0) {
        attention.push({
            text: `${orders.payment_failed} failed payment${orders.payment_failed === 1 ? '' : 's'}`,
            href: OrderController.index().url,
            tone: 'warn',
        });
    }

    if (bookings && bookings.unassigned > 0) {
        attention.push({
            text: `${bookings.unassigned} upcoming booking${bookings.unassigned === 1 ? ' has' : 's have'} no technician assigned`,
            href: DispatchBoardController.index().url,
            tone: 'bad',
        });
    }

    if (inventory && inventory.out_of_stock_count > 0) {
        attention.push({
            text: `${inventory.out_of_stock_count} stock line${inventory.out_of_stock_count === 1 ? ' is' : 's are'} out of stock`,
            href: StockLocationController.index().url,
            tone: 'bad',
        });
    }

    if (promotions && promotions.pending_claims > 0) {
        attention.push({
            text: `${promotions.pending_claims} price-guarantee claim${promotions.pending_claims === 1 ? '' : 's'} awaiting review`,
            href: PriceGuaranteeClaimController.index().url,
            tone: 'warn',
        });
    }

    return (
        <>
            <Head title="Dashboard" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Dashboard"
                    description={`What needs attention today, and how the business is doing over the last ${windowDays} days.`}
                />

                {!hasAnything && (
                    <Empty>
                        Your role doesn't have any dashboard widgets. Use the
                        sidebar to open the areas you can manage.
                    </Empty>
                )}

                {attention.length > 0 && (
                    <Section title="Needs attention">
                        <ul className="space-y-2 text-sm">
                            {attention.map((item) => (
                                <li key={item.text}>
                                    <Link
                                        href={item.href}
                                        className="flex items-center gap-2 hover:underline"
                                    >
                                        <span
                                            className={
                                                item.tone === 'bad'
                                                    ? 'size-2 rounded-full bg-red-500'
                                                    : 'size-2 rounded-full bg-amber-500'
                                            }
                                        />
                                        {item.text}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                {(sales || bookings || orders || customers) && (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {sales && (
                            <StatTile
                                label={`Net revenue (${windowDays} days)`}
                                value={formatCents(sales.net_revenue)}
                                hint={`${sales.paid_order_count} paid order${sales.paid_order_count === 1 ? '' : 's'} · avg ${formatCents(Math.round(sales.average_order_value))}`}
                                emphasis
                            />
                        )}
                        {sales && (
                            <StatTile
                                label={`Orders (${windowDays} days)`}
                                value={String(sales.order_count)}
                                hint={
                                    sales.refunds_total > 0
                                        ? `${formatCents(sales.refunds_total)} refunded`
                                        : 'No refunds'
                                }
                            />
                        )}
                        {bookings && (
                            <StatTile
                                label="Jobs today"
                                value={String(bookings.today_count)}
                                hint={`${bookings.next_seven_days} more in the next 7 days`}
                            />
                        )}
                        {customers && (
                            <StatTile
                                label="Customers"
                                value={String(customers.total)}
                                hint={`${customers.new_last_seven_days} new in the last 7 days`}
                            />
                        )}
                        {orders && (
                            <StatTile
                                label="Awaiting payment"
                                value={String(orders.awaiting_payment)}
                            />
                        )}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    {sales && (
                        <Section
                            title="Revenue — last 14 days"
                            description="Paid orders by day placed."
                            href={ReportingController.show('sales').url}
                            linkLabel="Sales report"
                        >
                            <RevenueBars daily={sales.daily} />
                        </Section>
                    )}

                    {sales && (
                        <Section
                            title="Orders by status"
                            description={`Orders placed in the last ${windowDays} days.`}
                            href={OrderController.index().url}
                        >
                            {Object.keys(sales.orders_by_status).length ===
                            0 ? (
                                <Empty>No orders in this period.</Empty>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {Object.entries(sales.orders_by_status)
                                        .sort((a, b) => b[1] - a[1])
                                        .map(([status, count]) => (
                                            <li
                                                key={status}
                                                className="flex items-center justify-between"
                                            >
                                                <Badge
                                                    variant={statusVariant(
                                                        status,
                                                    )}
                                                    className="capitalize"
                                                >
                                                    {label(status)}
                                                </Badge>
                                                <span className="tabular-nums">
                                                    {count}
                                                </span>
                                            </li>
                                        ))}
                                </ul>
                            )}
                        </Section>
                    )}

                    {bookings && (
                        <Section
                            title="Today's schedule"
                            description="Jobs booked for today, by start time."
                            href={DispatchBoardController.index().url}
                            linkLabel="Dispatch board"
                        >
                            {bookings.today.length === 0 ? (
                                <Empty>No jobs scheduled today.</Empty>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {bookings.today.map((booking) => (
                                        <li
                                            key={booking.id}
                                            className="flex items-center justify-between gap-3 py-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="font-medium tabular-nums">
                                                    {booking.slot_start.slice(
                                                        0,
                                                        5,
                                                    )}
                                                    –
                                                    {booking.slot_end.slice(
                                                        0,
                                                        5,
                                                    )}{' '}
                                                    <span className="font-normal">
                                                        {booking.customer ??
                                                            'Customer'}
                                                    </span>
                                                </p>
                                                <p className="text-muted-foreground truncate text-xs">
                                                    {booking.technician ??
                                                        'Unassigned'}
                                                    {booking.zone
                                                        ? ` · ${booking.zone}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={statusVariant(
                                                    booking.status,
                                                )}
                                                className="capitalize"
                                            >
                                                {label(booking.status)}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    )}

                    {orders && (
                        <Section
                            title="Recent orders"
                            href={OrderController.index().url}
                        >
                            {orders.recent.length === 0 ? (
                                <Empty>No orders yet.</Empty>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {orders.recent.map((order) => (
                                        <li
                                            key={order.id}
                                            className="flex items-center justify-between gap-3 py-2"
                                        >
                                            <div className="min-w-0">
                                                <Link
                                                    href={
                                                        OrderController.show(
                                                            order.id,
                                                        ).url
                                                    }
                                                    className="font-medium hover:underline"
                                                >
                                                    {order.order_number}
                                                </Link>
                                                <p className="text-muted-foreground truncate text-xs">
                                                    {order.customer ?? 'Guest'}{' '}
                                                    ·{' '}
                                                    {formatDateTime(
                                                        order.placed_at,
                                                    )}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span className="tabular-nums">
                                                    {formatCents(
                                                        order.grand_total,
                                                    )}
                                                </span>
                                                <Badge
                                                    variant={statusVariant(
                                                        order.status,
                                                    )}
                                                    className="capitalize"
                                                >
                                                    {label(order.status)}
                                                </Badge>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    )}

                    {inventory && (
                        <Section
                            title="Low stock"
                            description={`${inventory.low_stock_count} line${inventory.low_stock_count === 1 ? '' : 's'} at or below the reorder point.`}
                            href={StockLocationController.index().url}
                            linkLabel="Inventory"
                        >
                            {inventory.low_stock.length === 0 ? (
                                <Empty>Stock levels look healthy.</Empty>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {inventory.low_stock.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex items-center justify-between gap-3 py-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {item.model ?? 'Tyre'}{' '}
                                                    {item.size}
                                                </p>
                                                <p className="text-muted-foreground truncate text-xs">
                                                    {item.location}
                                                </p>
                                            </div>
                                            <span className="text-xs tabular-nums">
                                                {item.available} left · reorder
                                                at {item.reorder_point}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    )}

                    {(catalogue || promotions || reviews) && (
                        <Section title="Catalogue & content">
                            <dl className="grid grid-cols-2 gap-4 text-sm">
                                {catalogue && (
                                    <>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                <Link
                                                    href={
                                                        BrandController.index()
                                                            .url
                                                    }
                                                    className="hover:underline"
                                                >
                                                    Active brands
                                                </Link>
                                            </dt>
                                            <dd className="text-lg font-semibold tabular-nums">
                                                {catalogue.brands}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Tyre models
                                            </dt>
                                            <dd className="text-lg font-semibold tabular-nums">
                                                {catalogue.models}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Sellable sizes
                                            </dt>
                                            <dd className="text-lg font-semibold tabular-nums">
                                                {catalogue.variants}
                                            </dd>
                                            {catalogue.inactive_variants >
                                                0 && (
                                                <p className="text-muted-foreground text-xs">
                                                    {
                                                        catalogue.inactive_variants
                                                    }{' '}
                                                    inactive
                                                </p>
                                            )}
                                        </div>
                                    </>
                                )}
                                {promotions && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            <Link
                                                href={
                                                    PromotionController.index()
                                                        .url
                                                }
                                                className="hover:underline"
                                            >
                                                Running promotions
                                            </Link>
                                        </dt>
                                        <dd className="text-lg font-semibold tabular-nums">
                                            {promotions.active}
                                        </dd>
                                    </div>
                                )}
                                {reviews && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            <Link
                                                href={
                                                    ReviewController.index().url
                                                }
                                                className="hover:underline"
                                            >
                                                Google rating
                                            </Link>
                                        </dt>
                                        <dd className="text-lg font-semibold tabular-nums">
                                            {reviews.count > 0
                                                ? `${reviews.average_rating} ★`
                                                : '—'}
                                        </dd>
                                        <p className="text-muted-foreground text-xs">
                                            {reviews.count} visible
                                            {reviews.hidden_count > 0
                                                ? ` · ${reviews.hidden_count} hidden`
                                                : ''}
                                        </p>
                                    </div>
                                )}
                            </dl>
                        </Section>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
