import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Admin/Orders/OrderController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
    ORDER_STATUS_LABELS,
    PAYMENT_STATUS_LABELS,
    orderStatusBadgeVariant,
    paymentStatusBadgeVariant,
} from '@/lib/enums';
import { formatCents } from '@/lib/money';
import type {
    OrderFilters,
    OrderListRow,
    OrderStatus,
    Paginated,
    PaymentStatus,
} from '@/types/orders';
import type { BreadcrumbItem } from '@/types';

const ALL_VALUE = 'all';

const ORDER_STATUSES = Object.keys(ORDER_STATUS_LABELS) as OrderStatus[];
const PAYMENT_STATUSES = Object.keys(PAYMENT_STATUS_LABELS) as PaymentStatus[];

function applyFilters(
    next: Partial<Record<string, string | null>>,
    current: OrderFilters,
) {
    router.get(
        OrderController.index().url,
        {
            status: current.status,
            payment_status: current.payment_status,
            date_from: current.date_from,
            date_to: current.date_to,
            search: current.search,
            ...next,
        },
        { preserveState: true, preserveScroll: true },
    );
}

export default function OrdersIndex({
    filters,
    orders,
}: {
    filters: OrderFilters;
    orders: Paginated<OrderListRow>;
}) {
    const [searchInput, setSearchInput] = useState(filters.search ?? '');

    const submitSearch = (e: FormEvent) => {
        e.preventDefault();
        applyFilters({ search: searchInput || null }, filters);
    };

    const goToPage = (page: number) => {
        router.get(
            OrderController.index().url,
            { ...filters, page },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Orders" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Orders"
                    description="Search, view, cancel and refund orders. Refund_required orders need ops attention — a Stripe webhook confirmed payment after the linked booking's hold had already expired."
                />

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 pt-6">
                        <form onSubmit={submitSearch} className="grid gap-2">
                            <Label htmlFor="filter-search">
                                Order number or customer
                            </Label>
                            <div className="flex gap-2">
                                <Input
                                    id="filter-search"
                                    className="w-64"
                                    placeholder="TMS-... or email/name"
                                    value={searchInput}
                                    onChange={(e) =>
                                        setSearchInput(e.target.value)
                                    }
                                />
                                <Button type="submit" variant="outline">
                                    Search
                                </Button>
                            </div>
                        </form>

                        <div className="grid gap-2">
                            <Label htmlFor="filter-status">Status</Label>
                            <Select
                                value={filters.status ?? ALL_VALUE}
                                onValueChange={(v) =>
                                    applyFilters(
                                        { status: v === ALL_VALUE ? null : v },
                                        filters,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="filter-status"
                                    className="w-56"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_VALUE}>
                                        All statuses
                                    </SelectItem>
                                    {ORDER_STATUSES.map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {ORDER_STATUS_LABELS[status]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="filter-payment-status">
                                Payment status
                            </Label>
                            <Select
                                value={filters.payment_status ?? ALL_VALUE}
                                onValueChange={(v) =>
                                    applyFilters(
                                        {
                                            payment_status:
                                                v === ALL_VALUE ? null : v,
                                        },
                                        filters,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="filter-payment-status"
                                    className="w-48"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_VALUE}>
                                        All payment statuses
                                    </SelectItem>
                                    {PAYMENT_STATUSES.map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {PAYMENT_STATUS_LABELS[status]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="filter-date-from">From</Label>
                            <Input
                                id="filter-date-from"
                                type="date"
                                className="w-40"
                                value={filters.date_from ?? ''}
                                onChange={(e) =>
                                    applyFilters(
                                        { date_from: e.target.value || null },
                                        filters,
                                    )
                                }
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="filter-date-to">To</Label>
                            <Input
                                id="filter-date-to"
                                type="date"
                                className="w-40"
                                value={filters.date_to ?? ''}
                                onChange={(e) =>
                                    applyFilters(
                                        { date_to: e.target.value || null },
                                        filters,
                                    )
                                }
                            />
                        </div>

                        {(filters.status ||
                            filters.payment_status ||
                            filters.date_from ||
                            filters.date_to ||
                            filters.search) && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setSearchInput('');
                                    router.get(OrderController.index().url);
                                }}
                            >
                                Clear filters
                            </Button>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="pt-6">
                        {orders.data.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No orders match this filter.
                            </p>
                        ) : (
                            <>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="py-2 font-medium">
                                                Order
                                            </th>
                                            <th className="py-2 font-medium">
                                                Customer
                                            </th>
                                            <th className="py-2 font-medium">
                                                Status
                                            </th>
                                            <th className="py-2 font-medium">
                                                Payment
                                            </th>
                                            <th className="py-2 font-medium">
                                                Total
                                            </th>
                                            <th className="py-2 font-medium">
                                                Placed
                                            </th>
                                            <th className="py-2 font-medium" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {orders.data.map((order) => (
                                            <tr
                                                key={order.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-2 font-medium">
                                                    {order.order_number}
                                                </td>
                                                <td className="py-2">
                                                    {order.customer?.name ?? (
                                                        <span className="text-muted-foreground">
                                                            Guest
                                                        </span>
                                                    )}
                                                    {order.customer?.email && (
                                                        <div className="text-muted-foreground text-xs">
                                                            {
                                                                order.customer
                                                                    .email
                                                            }
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="py-2">
                                                    <Badge
                                                        variant={orderStatusBadgeVariant(
                                                            order.status,
                                                        )}
                                                    >
                                                        {
                                                            ORDER_STATUS_LABELS[
                                                                order.status
                                                            ]
                                                        }
                                                    </Badge>
                                                </td>
                                                <td className="py-2">
                                                    <Badge
                                                        variant={paymentStatusBadgeVariant(
                                                            order.payment_status,
                                                        )}
                                                    >
                                                        {
                                                            PAYMENT_STATUS_LABELS[
                                                                order
                                                                    .payment_status
                                                            ]
                                                        }
                                                    </Badge>
                                                </td>
                                                <td className="py-2">
                                                    {formatCents(
                                                        order.grand_total,
                                                    )}
                                                </td>
                                                <td className="text-muted-foreground py-2">
                                                    {order.placed_at
                                                        ? new Date(
                                                              order.placed_at,
                                                          ).toLocaleDateString(
                                                              'en-AU',
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="py-2 text-right">
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        size="sm"
                                                    >
                                                        <Link
                                                            href={
                                                                OrderController.show(
                                                                    order.id,
                                                                ).url
                                                            }
                                                        >
                                                            View
                                                        </Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>

                                <div className="text-muted-foreground flex items-center justify-between pt-4 text-sm">
                                    <span>
                                        Showing {orders.from ?? 0}–
                                        {orders.to ?? 0} of {orders.total}
                                    </span>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={orders.current_page <= 1}
                                            onClick={() =>
                                                goToPage(
                                                    orders.current_page - 1,
                                                )
                                            }
                                        >
                                            Previous
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                orders.current_page >=
                                                orders.last_page
                                            }
                                            onClick={() =>
                                                goToPage(
                                                    orders.current_page + 1,
                                                )
                                            }
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [
        { title: 'Orders', href: OrderController.index().url },
    ] satisfies BreadcrumbItem[],
};
