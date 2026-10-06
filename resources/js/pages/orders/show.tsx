import { formatDate } from '@/lib/date';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Admin/Orders/OrderController';
import OrderRefundController from '@/actions/App/Http/Controllers/Admin/Orders/OrderRefundController';
import AlertError from '@/components/alert-error';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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
    PAYMENT_STATUS_LABELS,
    PAYMENT_TRANSACTION_STATUS_LABELS,
    bookingStatusBadgeVariant,
    orderStatusBadgeVariant,
    paymentStatusBadgeVariant,
} from '@/lib/enums';
import { dollarsInputToCents, formatCents } from '@/lib/money';
import type { OrderDetail, OrderStatus } from '@/types/orders';
import type { BreadcrumbItem } from '@/types';

/**
 * Refund confirm dialog. The `Idempotency-Key` header is generated exactly
 * once per refund-intent — when the dialog opens — and reused for every
 * retry of that same submission (a double-click before the button
 * disables, a network-lag resubmit). It is NEVER regenerated inline at
 * submit-time: doing that would silently reintroduce the double-refund bug
 * security-agent found and backend-agent fixed (a double-click producing
 * two separate Stripe refunds because the key was minted per request). The
 * submit button's `disabled={form.processing}` below is a secondary,
 * cheap defense-in-depth measure — the reused key is the actual guarantee.
 */
function RefundDialog({ order }: { order: OrderDetail }) {
    const [open, setOpen] = useState(false);
    const idempotencyKeyRef = useRef<string>(crypto.randomUUID());

    const form = useForm<{ amount: string; reason: string }>({
        amount: '',
        reason: '',
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        // A dialog (re)open is a new refund-intent — mint a fresh key here,
        // once, then leave it alone for the rest of this dialog session.
        idempotencyKeyRef.current = crypto.randomUUID();
        form.reset();
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- only `open` should retrigger this; `form` identity is stable from useForm.
    }, [open]);

    const submit = () => {
        const payload = {
            amount:
                form.data.amount.trim() === ''
                    ? null
                    : dollarsInputToCents(form.data.amount),
            reason: form.data.reason,
        };

        form.transform(() => payload);

        form.post(OrderRefundController.store(order.id).url, {
            headers: { 'Idempotency-Key': idempotencyKeyRef.current },
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    // `idempotency_key` isn't a field in this form's own data shape (it
    // travels as a header, not a body field), so `useForm`'s error typing
    // doesn't know about it — the server can still flash it as a
    // validation error (see `App\Http\Middleware\Idempotency`'s Inertia
    // branch), hence the cast.
    const idempotencyError = (form.errors as Record<string, string | undefined>)
        .idempotency_key;
    const nonFieldErrors = idempotencyError ? [idempotencyError] : [];

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    Refund
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Refund order {order.order_number}</DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    {nonFieldErrors.length > 0 && (
                        <AlertError
                            title="Refund could not be submitted"
                            errors={nonFieldErrors}
                        />
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="refund-amount">
                            Amount ($) — leave blank for a full refund
                        </Label>
                        <Input
                            id="refund-amount"
                            type="number"
                            min={0}
                            step="0.01"
                            placeholder={formatCents(order.grand_total)}
                            value={form.data.amount}
                            onChange={(e) =>
                                form.setData('amount', e.target.value)
                            }
                        />
                        <InputError message={form.errors.amount} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="refund-reason">Reason</Label>
                        <Input
                            id="refund-reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="e.g. Customer requested cancellation"
                        />
                        <InputError message={form.errors.reason} />
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        Confirm refund
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function CancelOrderButton({ order }: { order: OrderDetail }) {
    const cancel = () => {
        if (
            !confirm(
                `Cancel order ${order.order_number}? This will also free the linked booking's technician/van slot, if it's still held.`,
            )
        ) {
            return;
        }

        router.post(
            OrderController.cancel(order.id).url,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <Button variant="outline" size="sm" onClick={cancel}>
            Cancel order
        </Button>
    );
}

function StatusUpdateControl({
    order,
    manualStatusOptions,
}: {
    order: OrderDetail;
    manualStatusOptions: OrderStatus[];
}) {
    const [target, setTarget] = useState<OrderStatus | ''>('');

    if (manualStatusOptions.length === 0) {
        return null;
    }

    const apply = () => {
        if (!target) {
            return;
        }

        router.patch(
            OrderController.updateStatus(order.id).url,
            { status: target },
            { preserveScroll: true, onSuccess: () => setTarget('') },
        );
    };

    return (
        <div className="flex items-center gap-2">
            <Select
                value={target}
                onValueChange={(v) => setTarget(v as OrderStatus)}
            >
                <SelectTrigger className="w-44">
                    <SelectValue placeholder="Set status…" />
                </SelectTrigger>
                <SelectContent>
                    {manualStatusOptions.map((status) => (
                        <SelectItem key={status} value={status}>
                            Mark {ORDER_STATUS_LABELS[status]}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Button
                variant="outline"
                size="sm"
                disabled={!target}
                onClick={apply}
            >
                Apply
            </Button>
        </div>
    );
}

export default function OrderShow({
    order,
    cancellable,
    manualStatusOptions,
}: {
    order: OrderDetail;
    cancellable: boolean;
    manualStatusOptions: OrderStatus[];
}) {
    return (
        <>
            <Head title={`Order ${order.order_number}`} />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Order ${order.order_number}`}
                        description={
                            order.customer
                                ? `${order.customer.name} · ${order.customer.email}`
                                : 'Guest order'
                        }
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={orderStatusBadgeVariant(order.status)}>
                            {ORDER_STATUS_LABELS[order.status]}
                        </Badge>
                        <Badge
                            variant={paymentStatusBadgeVariant(
                                order.payment_status,
                            )}
                        >
                            {PAYMENT_STATUS_LABELS[order.payment_status]}
                        </Badge>

                        <Can permission="orders.manage">
                            <StatusUpdateControl
                                order={order}
                                manualStatusOptions={manualStatusOptions}
                            />
                            {cancellable && <CancelOrderButton order={order} />}
                        </Can>

                        <Can permission="orders.refund">
                            <RefundDialog order={order} />
                        </Can>
                    </div>
                </div>

                {order.status === 'cancelled' &&
                    (order.payment_status === 'paid' ||
                        order.payment_status === 'partially_refunded') && (
                        <div className="rounded-md border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                            This order is cancelled but still has a paid balance
                            — process a refund if one is owed.
                        </div>
                    )}

                {order.status === 'refund_required' && (
                    <div className="border-destructive/50 bg-destructive/10 text-destructive rounded-md border px-4 py-3 text-sm">
                        Payment was confirmed after this order's booking hold
                        had already expired or been cancelled. Process a refund
                        to resolve this.
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Line items</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="py-2 font-medium">
                                                Item
                                            </th>
                                            <th className="py-2 font-medium">
                                                Qty
                                            </th>
                                            <th className="py-2 font-medium">
                                                Unit price
                                            </th>
                                            <th className="py-2 text-right font-medium">
                                                Line total
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {order.line_items.map((item) => {
                                            const variant = item.tyre_variant;
                                            const model = variant?.tyre_model;

                                            return (
                                                <tr
                                                    key={item.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="py-2">
                                                        {model ? (
                                                            <>
                                                                {
                                                                    model.brand
                                                                        ?.name
                                                                }{' '}
                                                                {model.name}
                                                                <div className="text-muted-foreground text-xs">
                                                                    {
                                                                        variant.sku
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {
                                                                        variant.width
                                                                    }
                                                                    /
                                                                    {
                                                                        variant.profile
                                                                    }
                                                                    R
                                                                    {
                                                                        variant.rim_diameter
                                                                    }
                                                                </div>
                                                            </>
                                                        ) : (
                                                            (variant?.sku ??
                                                            'Tyre')
                                                        )}
                                                    </td>
                                                    <td className="py-2">
                                                        {item.quantity}
                                                    </td>
                                                    <td className="py-2">
                                                        {formatCents(
                                                            item.unit_price,
                                                        )}
                                                    </td>
                                                    <td className="py-2 text-right">
                                                        {formatCents(
                                                            item.line_total,
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>

                                <div className="mt-4 space-y-1 border-t pt-4 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Subtotal
                                        </span>
                                        <span>
                                            {formatCents(order.subtotal)}
                                        </span>
                                    </div>
                                    {order.discount_total > 0 && (
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                Discount
                                            </span>
                                            <span>
                                                -
                                                {formatCents(
                                                    order.discount_total,
                                                )}
                                            </span>
                                        </div>
                                    )}
                                    {order.service_fee_total > 0 && (
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                Service fee
                                            </span>
                                            <span>
                                                {formatCents(
                                                    order.service_fee_total,
                                                )}
                                            </span>
                                        </div>
                                    )}
                                    <div className="flex justify-between font-medium">
                                        <span>Total (GST-inclusive)</span>
                                        <span>
                                            {formatCents(order.grand_total)}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground flex justify-between text-xs">
                                        <span>
                                            Includes GST (informational)
                                        </span>
                                        <span>
                                            {formatCents(order.tax_total)}
                                        </span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Payment history</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {order.payments.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        No payment transactions yet.
                                    </p>
                                ) : (
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left">
                                                <th className="py-2 font-medium">
                                                    Type
                                                </th>
                                                <th className="py-2 font-medium">
                                                    Method
                                                </th>
                                                <th className="py-2 font-medium">
                                                    Status
                                                </th>
                                                <th className="py-2 font-medium">
                                                    Reference
                                                </th>
                                                <th className="py-2 font-medium">
                                                    Date
                                                </th>
                                                <th className="py-2 text-right font-medium">
                                                    Amount
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {order.payments.map((payment) => (
                                                <tr
                                                    key={payment.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="py-2 capitalize">
                                                        {payment.type}
                                                    </td>
                                                    <td className="text-muted-foreground py-2 capitalize">
                                                        {payment.method.replace(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </td>
                                                    <td className="py-2">
                                                        <Badge variant="outline">
                                                            {
                                                                PAYMENT_TRANSACTION_STATUS_LABELS[
                                                                    payment
                                                                        .status
                                                                ]
                                                            }
                                                        </Badge>
                                                    </td>
                                                    <td className="text-muted-foreground py-2">
                                                        {
                                                            payment.gateway_reference
                                                        }
                                                    </td>
                                                    <td className="text-muted-foreground py-2">
                                                        {new Date(
                                                            payment.created_at,
                                                        ).toLocaleString(
                                                            'en-AU',
                                                        )}
                                                    </td>
                                                    <td className="py-2 text-right">
                                                        {payment.type ===
                                                        'refund'
                                                            ? '-'
                                                            : ''}
                                                        {formatCents(
                                                            payment.amount,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Fitting address</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1 text-sm">
                                <p>{order.address.line1}</p>
                                {order.address.line2 && (
                                    <p>{order.address.line2}</p>
                                )}
                                <p>
                                    {order.address.suburb?.name}{' '}
                                    {order.address.suburb?.state?.code}{' '}
                                    {order.address.postcode}
                                </p>
                                {order.address.access_instructions && (
                                    <p className="text-muted-foreground pt-2">
                                        {order.address.access_instructions}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Appointment</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1 text-sm">
                                {order.booking ? (
                                    <>
                                        <div className="flex items-center justify-between">
                                            <span>
                                                {formatDate(
                                                    order.booking
                                                        .scheduled_date,
                                                )}
                                            </span>
                                            <Badge
                                                variant={bookingStatusBadgeVariant(
                                                    order.booking.status,
                                                )}
                                            >
                                                {
                                                    BOOKING_STATUS_LABELS[
                                                        order.booking.status
                                                    ]
                                                }
                                            </Badge>
                                        </div>
                                        <p>
                                            {order.booking.slot_start.slice(
                                                0,
                                                5,
                                            )}
                                            –
                                            {order.booking.slot_end.slice(0, 5)}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {order.booking.service_zone?.name}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {order.booking.technician?.name ??
                                                'Unassigned'}{' '}
                                            ·{' '}
                                            {order.booking.van?.name ??
                                                'No van'}
                                        </p>
                                        {order.booking.addons &&
                                            order.booking.addons.length > 0 && (
                                                <div className="flex flex-wrap gap-1 pt-1">
                                                    {order.booking.addons.map(
                                                        (addon) => (
                                                            <Badge
                                                                key={addon}
                                                                variant="outline"
                                                            >
                                                                {addon}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                    </>
                                ) : (
                                    <p className="text-muted-foreground">
                                        No linked booking.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}

OrderShow.layout = {
    breadcrumbs: [
        { title: 'Orders', href: OrderController.index().url },
        { title: 'Order detail', href: '#' },
    ] satisfies BreadcrumbItem[],
};
