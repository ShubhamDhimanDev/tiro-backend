import { Head, Link } from '@inertiajs/react';
import CustomerController from '@/actions/App/Http/Controllers/Admin/Customers/CustomerController';
import OrderController from '@/actions/App/Http/Controllers/Admin/Orders/OrderController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    NOTIFICATION_CHANNEL_LABELS,
    NOTIFICATION_DELIVERY_STATUS_LABELS,
    ORDER_STATUS_LABELS,
    PAYMENT_STATUS_LABELS,
    notificationDeliveryStatusBadgeVariant,
    orderStatusBadgeVariant,
    paymentStatusBadgeVariant,
} from '@/lib/enums';
import { formatCents } from '@/lib/money';
import type {
    CustomerAddressRow,
    CustomerNotificationRow,
    CustomerOrderRow,
    CustomerProfile,
    CustomerVehicleRow,
    SavedFitmentSize,
} from '@/types/customers';
import type { BreadcrumbItem } from '@/types';

function formatFitmentSize(size: SavedFitmentSize): string {
    return `${size.width}/${size.profile}R${size.rim_diameter}`;
}

function FitmentSummary({
    savedFitment,
}: {
    savedFitment: CustomerVehicleRow['saved_fitment'];
}) {
    if ('all' in savedFitment) {
        return <span>{formatFitmentSize(savedFitment.all)}</span>;
    }

    if ('front' in savedFitment && 'rear' in savedFitment) {
        return (
            <span>
                F {formatFitmentSize(savedFitment.front)} · R{' '}
                {formatFitmentSize(savedFitment.rear)}
            </span>
        );
    }

    return <span className="text-muted-foreground">No saved size</span>;
}

function ProfileCard({ customer }: { customer: CustomerProfile }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Profile</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
                <div className="flex justify-between">
                    <span className="text-muted-foreground">Name</span>
                    <span>{customer.name}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">Email</span>
                    <span>{customer.email}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">Mobile</span>
                    <span>{customer.mobile ?? '—'}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        Email verified
                    </span>
                    <span>
                        {customer.email_verified_at
                            ? new Date(
                                  customer.email_verified_at,
                              ).toLocaleDateString('en-AU')
                            : 'Not verified'}
                    </span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">Joined</span>
                    <span>
                        {new Date(customer.created_at).toLocaleDateString(
                            'en-AU',
                        )}
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

function VehiclesCard({ vehicles }: { vehicles: CustomerVehicleRow[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Saved vehicles</CardTitle>
            </CardHeader>
            <CardContent>
                {vehicles.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No saved vehicles.
                    </p>
                ) : (
                    <ul className="space-y-3 text-sm">
                        {vehicles.map((vehicle) => (
                            <li
                                key={vehicle.id}
                                className="border-b pb-3 last:border-0 last:pb-0"
                            >
                                <div className="flex items-center justify-between">
                                    <span className="font-medium">
                                        {vehicle.label ??
                                            (vehicle.vehicle
                                                ? `${vehicle.vehicle.make} ${vehicle.vehicle.model}`
                                                : 'Unlabelled vehicle')}
                                    </span>
                                    {vehicle.is_default && (
                                        <Badge variant="outline">Default</Badge>
                                    )}
                                </div>
                                {vehicle.vehicle && (
                                    <p className="text-muted-foreground">
                                        {vehicle.vehicle.make}{' '}
                                        {vehicle.vehicle.model}
                                        {vehicle.vehicle.series
                                            ? ` ${vehicle.vehicle.series}`
                                            : ''}{' '}
                                        ({vehicle.vehicle.year_from}–
                                        {vehicle.vehicle.year_to})
                                    </p>
                                )}
                                <p className="text-muted-foreground">
                                    <FitmentSummary
                                        savedFitment={vehicle.saved_fitment}
                                    />
                                </p>
                                {(vehicle.rego || vehicle.state) && (
                                    <p className="text-muted-foreground">
                                        Rego: {vehicle.rego ?? '—'}
                                        {vehicle.state
                                            ? ` (${vehicle.state})`
                                            : ''}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function AddressesCard({ addresses }: { addresses: CustomerAddressRow[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Saved addresses</CardTitle>
            </CardHeader>
            <CardContent>
                {addresses.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No saved addresses.
                    </p>
                ) : (
                    <ul className="space-y-3 text-sm">
                        {addresses.map((address) => (
                            <li
                                key={address.id}
                                className="border-b pb-3 last:border-0 last:pb-0"
                            >
                                <div className="flex items-center justify-between">
                                    <span className="font-medium">
                                        {address.label ?? address.line1}
                                    </span>
                                    {address.is_default && (
                                        <Badge variant="outline">Default</Badge>
                                    )}
                                </div>
                                <p className="text-muted-foreground">
                                    {address.line1}
                                    {address.line2 ? `, ${address.line2}` : ''}
                                </p>
                                <p className="text-muted-foreground">
                                    {address.suburb?.name}{' '}
                                    {address.suburb?.state?.code}{' '}
                                    {address.postcode}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function OrderHistoryCard({
    orders,
    ordersCount,
    customerEmail,
}: {
    orders: CustomerOrderRow[];
    ordersCount: number;
    customerEmail: string;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Order history</CardTitle>
            </CardHeader>
            <CardContent>
                {orders.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No orders yet.
                    </p>
                ) : (
                    <>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">Order</th>
                                    <th className="py-2 font-medium">Status</th>
                                    <th className="py-2 font-medium">
                                        Payment
                                    </th>
                                    <th className="py-2 font-medium">Total</th>
                                    <th className="py-2 font-medium">Placed</th>
                                    <th className="py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {orders.map((order) => (
                                    <tr
                                        key={order.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 font-medium">
                                            {order.order_number}
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
                                                        order.payment_status
                                                    ]
                                                }
                                            </Badge>
                                        </td>
                                        <td className="py-2">
                                            {formatCents(order.grand_total)}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {order.placed_at
                                                ? new Date(
                                                      order.placed_at,
                                                  ).toLocaleDateString('en-AU')
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

                        {ordersCount > orders.length && (
                            <p className="text-muted-foreground pt-4 text-sm">
                                Showing the {orders.length} most recent of{' '}
                                {ordersCount} orders.{' '}
                                <Link
                                    href={
                                        OrderController.index({
                                            query: {
                                                search: customerEmail,
                                            },
                                        }).url
                                    }
                                    className="text-primary underline underline-offset-2"
                                >
                                    Search all orders for this customer
                                </Link>
                                .
                            </p>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function NotificationsCard({
    notifications,
}: {
    notifications: CustomerNotificationRow[];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Recent notifications</CardTitle>
            </CardHeader>
            <CardContent>
                {notifications.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No notifications sent yet.
                    </p>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="py-2 font-medium">Type</th>
                                <th className="py-2 font-medium">Channel</th>
                                <th className="py-2 font-medium">Status</th>
                                <th className="py-2 font-medium">Recipient</th>
                                <th className="py-2 font-medium">Sent</th>
                            </tr>
                        </thead>
                        <tbody>
                            {notifications.map((notification) => (
                                <tr
                                    key={notification.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="py-2 font-mono text-xs">
                                        {notification.type}
                                    </td>
                                    <td className="py-2">
                                        {
                                            NOTIFICATION_CHANNEL_LABELS[
                                                notification.channel
                                            ]
                                        }
                                    </td>
                                    <td className="py-2">
                                        <Badge
                                            variant={notificationDeliveryStatusBadgeVariant(
                                                notification.status,
                                            )}
                                        >
                                            {
                                                NOTIFICATION_DELIVERY_STATUS_LABELS[
                                                    notification.status
                                                ]
                                            }
                                        </Badge>
                                        {notification.status === 'failed' &&
                                            notification.error_message && (
                                                <p className="text-destructive mt-1 text-xs">
                                                    {notification.error_message}
                                                </p>
                                            )}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {notification.recipient}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {new Date(
                                            notification.created_at,
                                        ).toLocaleString('en-AU')}
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

export default function CustomerShow({
    customer,
    vehicles,
    addresses,
    orders,
    ordersCount,
    notifications,
}: {
    customer: CustomerProfile;
    vehicles: CustomerVehicleRow[];
    addresses: CustomerAddressRow[];
    orders: CustomerOrderRow[];
    ordersCount: number;
    notifications: CustomerNotificationRow[];
}) {
    return (
        <>
            <Head title={customer.name} />

            <div className="space-y-6 p-4">
                <Heading title={customer.name} description={customer.email} />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <OrderHistoryCard
                            orders={orders}
                            ordersCount={ordersCount}
                            customerEmail={customer.email}
                        />
                        <NotificationsCard notifications={notifications} />
                    </div>

                    <div className="space-y-6">
                        <ProfileCard customer={customer} />
                        <VehiclesCard vehicles={vehicles} />
                        <AddressesCard addresses={addresses} />
                    </div>
                </div>
            </div>
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [
        { title: 'Customers', href: CustomerController.index().url },
        { title: 'Customer detail', href: '#' },
    ] satisfies BreadcrumbItem[],
};
