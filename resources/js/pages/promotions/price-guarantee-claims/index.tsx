import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PriceGuaranteeClaimController from '@/actions/App/Http/Controllers/Admin/Promotions/PriceGuaranteeClaimController';
import PromotionController from '@/actions/App/Http/Controllers/Admin/Promotions/PromotionController';
import { Can } from '@/components/can';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
    PRICE_GUARANTEE_CLAIM_STATUS_LABELS,
    priceGuaranteeClaimStatusBadgeVariant,
} from '@/lib/enums';
import { dollarsInputToCents, formatCents } from '@/lib/money';
import type { Paginated } from '@/types/orders';
import type {
    PriceGuaranteeClaim,
    PriceGuaranteeClaimStatus,
} from '@/types/promotions';
import type { BreadcrumbItem } from '@/types';

const ALL_VALUE = 'all';
const CLAIM_STATUSES = Object.keys(
    PRICE_GUARANTEE_CLAIM_STATUS_LABELS,
) as PriceGuaranteeClaimStatus[];

function ApproveClaimDialog({ claim }: { claim: PriceGuaranteeClaim }) {
    const [open, setOpen] = useState(false);

    const form = useForm<{ amount: string; admin_note: string }>({
        amount: '',
        admin_note: '',
    });

    const submit = () => {
        const payload = {
            approved_discount_amount: dollarsInputToCents(form.data.amount),
            admin_note: form.data.admin_note || null,
        };

        form.transform(() => payload);

        form.post(PriceGuaranteeClaimController.approve(claim.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    // `approved_discount_amount` isn't a field in this dialog's own form
    // data shape (the client field is `amount`, converted at submit time),
    // so the server's 422 cap-exceeded error doesn't type against
    // `form.errors`'s own-field-keyed shape — same cast pattern as
    // `OrderShow`'s `RefundDialog` for its `idempotency_key` error.
    const amountError = (form.errors as Record<string, string | undefined>)
        .approved_discount_amount;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Approve</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Approve claim #{claim.id}</DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <p className="text-muted-foreground text-sm">
                        Competitor price: {formatCents(claim.competitor_price)}{' '}
                        —{' '}
                        <a
                            href={claim.competitor_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline"
                        >
                            {claim.competitor_url}
                        </a>
                    </p>

                    <div className="grid gap-2">
                        <Label htmlFor="approved-amount">
                            Approved discount ($)
                        </Label>
                        <Input
                            id="approved-amount"
                            type="number"
                            min={0}
                            step="0.01"
                            value={form.data.amount}
                            onChange={(e) =>
                                form.setData('amount', e.target.value)
                            }
                        />
                        <InputError message={amountError} />
                        <p className="text-muted-foreground text-xs">
                            Capped server-side at the matched line's own value —
                            the request is rejected with an error here if this
                            exceeds that cap.
                        </p>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="approve-note">
                            Admin note (optional)
                        </Label>
                        <textarea
                            id="approve-note"
                            className="border-input min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                            value={form.data.admin_note}
                            onChange={(e) =>
                                form.setData('admin_note', e.target.value)
                            }
                        />
                        <InputError message={form.errors.admin_note} />
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        Confirm approval
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RejectClaimDialog({ claim }: { claim: PriceGuaranteeClaim }) {
    const [open, setOpen] = useState(false);

    const form = useForm<{ admin_note: string }>({ admin_note: '' });

    const submit = () => {
        form.post(PriceGuaranteeClaimController.reject(claim.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Reject
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Reject claim #{claim.id}</DialogTitle>
                </DialogHeader>

                <div className="grid gap-2">
                    <Label htmlFor="reject-note">Admin note (required)</Label>
                    <textarea
                        id="reject-note"
                        className="border-input min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        value={form.data.admin_note}
                        onChange={(e) =>
                            form.setData('admin_note', e.target.value)
                        }
                    />
                    <InputError message={form.errors.admin_note} />
                </div>

                <DialogFooter>
                    <Button
                        variant="destructive"
                        disabled={form.processing}
                        onClick={submit}
                    >
                        Confirm rejection
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function PriceGuaranteeClaimsIndex({
    filters,
    claims,
}: {
    filters: { status: PriceGuaranteeClaimStatus | null };
    claims: Paginated<PriceGuaranteeClaim>;
}) {
    const applyStatus = (status: string) => {
        router.get(
            PriceGuaranteeClaimController.index().url,
            { status: status === ALL_VALUE ? null : status },
            { preserveState: true, preserveScroll: true },
        );
    };

    const goToPage = (page: number) => {
        router.get(
            PriceGuaranteeClaimController.index().url,
            { status: filters.status, page },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Price-guarantee claims" />

            <Card>
                <CardContent className="flex flex-wrap items-end gap-4 pt-6">
                    <div className="grid gap-2">
                        <Label htmlFor="filter-status">Status</Label>
                        <Select
                            value={filters.status ?? ALL_VALUE}
                            onValueChange={applyStatus}
                        >
                            <SelectTrigger id="filter-status" className="w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL_VALUE}>
                                    All statuses
                                </SelectItem>
                                {CLAIM_STATUSES.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {
                                            PRICE_GUARANTEE_CLAIM_STATUS_LABELS[
                                                status
                                            ]
                                        }
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="pt-6">
                    {claims.data.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No claims match this filter.
                        </p>
                    ) : (
                        <>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Customer
                                        </th>
                                        <th className="py-2 font-medium">
                                            Tyre
                                        </th>
                                        <th className="py-2 font-medium">
                                            Competitor
                                        </th>
                                        <th className="py-2 font-medium">
                                            Order
                                        </th>
                                        <th className="py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="py-2 font-medium">
                                            Submitted
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {claims.data.map((claim) => (
                                        <tr
                                            key={claim.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2">
                                                {claim.customer?.name ?? '—'}
                                                <div className="text-muted-foreground text-xs">
                                                    {claim.customer?.email}
                                                </div>
                                            </td>
                                            <td className="py-2">
                                                {claim.tyre_variant?.tyre_model
                                                    ?.name ??
                                                    claim.tyre_variant?.sku ??
                                                    '—'}
                                            </td>
                                            <td className="py-2">
                                                {formatCents(
                                                    claim.competitor_price,
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {claim.order?.order_number ?? (
                                                    <span className="text-muted-foreground">
                                                        Pre-purchase
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                <Badge
                                                    variant={priceGuaranteeClaimStatusBadgeVariant(
                                                        claim.status,
                                                    )}
                                                >
                                                    {
                                                        PRICE_GUARANTEE_CLAIM_STATUS_LABELS[
                                                            claim.status
                                                        ]
                                                    }
                                                </Badge>
                                            </td>
                                            <td className="text-muted-foreground py-2">
                                                {new Date(
                                                    claim.created_at,
                                                ).toLocaleDateString('en-AU')}
                                            </td>
                                            <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                                {claim.status === 'pending' && (
                                                    <Can permission="promotions.manage">
                                                        <ApproveClaimDialog
                                                            claim={claim}
                                                        />
                                                        <RejectClaimDialog
                                                            claim={claim}
                                                        />
                                                    </Can>
                                                )}
                                                {claim.status !== 'pending' &&
                                                    claim.admin_note && (
                                                        <span className="text-muted-foreground text-xs">
                                                            {claim.admin_note}
                                                        </span>
                                                    )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            <div className="text-muted-foreground flex items-center justify-between pt-4 text-sm">
                                <span>
                                    Showing {claims.from ?? 0}–{claims.to ?? 0}{' '}
                                    of {claims.total}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={claims.current_page <= 1}
                                        onClick={() =>
                                            goToPage(claims.current_page - 1)
                                        }
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            claims.current_page >=
                                            claims.last_page
                                        }
                                        onClick={() =>
                                            goToPage(claims.current_page + 1)
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
        </>
    );
}

PriceGuaranteeClaimsIndex.layout = {
    breadcrumbs: [
        { title: 'Promotions', href: PromotionController.index().url },
        {
            title: 'Price-guarantee claims',
            href: PriceGuaranteeClaimController.index().url,
        },
    ] satisfies BreadcrumbItem[],
};
