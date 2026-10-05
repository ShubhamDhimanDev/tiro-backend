import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PriceRuleController from '@/actions/App/Http/Controllers/Admin/Promotions/PriceRuleController';
import PromotionController from '@/actions/App/Http/Controllers/Admin/Promotions/PromotionController';
import { Can } from '@/components/can';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    CANCELLATION_FEE_TYPE_OPTIONS,
    STATUS_OPTIONS,
    statusBadgeVariant,
} from '@/lib/enums';
import {
    centsToDollarsInput,
    dollarsInputToCents,
    formatCents,
} from '@/lib/money';
import type { CancellationFeeType } from '@/types/bookings';
import type { Status } from '@/types/catalog';
import type { PriceRule } from '@/types/promotions';
import type { BreadcrumbItem } from '@/types';

type PriceRuleFormData = {
    service_zone_id: string;
    fee_type: CancellationFeeType;
    fee_amount: string;
    fee_percent: string;
    status: Status;
};

function PriceRuleFormDialog({
    priceRule,
    serviceZones,
}: {
    priceRule?: PriceRule;
    serviceZones: { id: number; name: string }[];
}) {
    const isEdit = !!priceRule;
    const [open, setOpen] = useState(false);

    const form = useForm<PriceRuleFormData>({
        service_zone_id: priceRule ? String(priceRule.service_zone_id) : '',
        fee_type: priceRule?.fee_type ?? 'flat',
        fee_amount: centsToDollarsInput(priceRule?.fee_amount),
        fee_percent:
            priceRule?.fee_percent === null ||
            priceRule?.fee_percent === undefined
                ? ''
                : String(priceRule.fee_percent),
        status: priceRule?.status ?? 'active',
    });

    const isFlat = form.data.fee_type === 'flat';

    const submit = () => {
        const payload = {
            service_zone_id: form.data.service_zone_id,
            fee_type: form.data.fee_type,
            fee_amount: isFlat
                ? dollarsInputToCents(form.data.fee_amount)
                : null,
            fee_percent: isFlat
                ? null
                : Number.parseInt(form.data.fee_percent, 10) || 0,
            status: form.data.status,
        };

        form.transform(() => payload);

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!isEdit) {
                    form.reset();
                }
            },
        };

        if (isEdit) {
            form.put(PriceRuleController.update(priceRule.id).url, options);
        } else {
            form.post(PriceRuleController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New price rule'}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit price rule' : 'New price rule'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="service_zone_id">Service zone</Label>
                        <Select
                            value={form.data.service_zone_id}
                            onValueChange={(v) =>
                                form.setData('service_zone_id', v)
                            }
                        >
                            <SelectTrigger
                                id="service_zone_id"
                                className="w-full"
                            >
                                <SelectValue placeholder="Select a zone" />
                            </SelectTrigger>
                            <SelectContent>
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
                        <InputError message={form.errors.service_zone_id} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="fee_type">Fee type</Label>
                            <Select
                                value={form.data.fee_type}
                                onValueChange={(v) =>
                                    form.setData(
                                        'fee_type',
                                        v as CancellationFeeType,
                                    )
                                }
                            >
                                <SelectTrigger id="fee_type" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CANCELLATION_FEE_TYPE_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.fee_type} />
                        </div>

                        {isFlat ? (
                            <div className="grid gap-2">
                                <Label htmlFor="fee_amount">Fee ($)</Label>
                                <Input
                                    id="fee_amount"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.data.fee_amount}
                                    onChange={(e) =>
                                        form.setData(
                                            'fee_amount',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.fee_amount} />
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="fee_percent">Fee (%)</Label>
                                <Input
                                    id="fee_percent"
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={form.data.fee_percent}
                                    onChange={(e) =>
                                        form.setData(
                                            'fee_percent',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.fee_percent} />
                            </div>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="status">Status</Label>
                        <Select
                            value={form.data.status}
                            onValueChange={(v) =>
                                form.setData('status', v as Status)
                            }
                        >
                            <SelectTrigger id="status" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {STATUS_OPTIONS.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.status} />
                        <p className="text-muted-foreground text-xs">
                            Only one active rule is allowed per zone — set the
                            old one to inactive before activating a replacement.
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create price rule'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeletePriceRuleButton({ priceRule }: { priceRule: PriceRule }) {
    const destroy = () => {
        if (
            !confirm(
                `Delete the price rule for ${priceRule.service_zone?.name ?? 'this zone'}?`,
            )
        ) {
            return;
        }

        router.delete(PriceRuleController.destroy(priceRule.id).url, {
            preserveScroll: true,
        });
    };

    return (
        <Button variant="outline" size="sm" onClick={destroy}>
            Delete
        </Button>
    );
}

export default function PriceRulesIndex({
    priceRules,
    serviceZones,
}: {
    priceRules: PriceRule[];
    serviceZones: { id: number; name: string }[];
}) {
    return (
        <>
            <Head title="Price rules" />

            <div className="flex justify-end">
                <Can permission="promotions.manage">
                    <PriceRuleFormDialog serviceZones={serviceZones} />
                </Can>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Price rules</CardTitle>
                    <CardDescription>
                        Zone-level service-fee adjustments — a flat or
                        percentage add-on to the order subtotal for a given
                        service zone. Not a per-product repricing matrix.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {priceRules.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No price rules yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">Zone</th>
                                    <th className="py-2 font-medium">
                                        Fee type
                                    </th>
                                    <th className="py-2 font-medium">Fee</th>
                                    <th className="py-2 font-medium">Status</th>
                                    <th className="py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {priceRules.map((rule) => (
                                    <tr
                                        key={rule.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 font-medium">
                                            {rule.service_zone?.name ?? '—'}
                                        </td>
                                        <td className="py-2 capitalize">
                                            {rule.fee_type}
                                        </td>
                                        <td className="py-2">
                                            {rule.fee_type === 'flat'
                                                ? formatCents(
                                                      rule.fee_amount ?? 0,
                                                  )
                                                : `${rule.fee_percent ?? 0}%`}
                                        </td>
                                        <td className="py-2">
                                            <Badge
                                                variant={statusBadgeVariant(
                                                    rule.status,
                                                )}
                                            >
                                                {rule.status}
                                            </Badge>
                                        </td>
                                        <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                            <Can permission="promotions.manage">
                                                <PriceRuleFormDialog
                                                    priceRule={rule}
                                                    serviceZones={serviceZones}
                                                />
                                                <DeletePriceRuleButton
                                                    priceRule={rule}
                                                />
                                            </Can>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </CardContent>
            </Card>
        </>
    );
}

PriceRulesIndex.layout = {
    breadcrumbs: [
        { title: 'Promotions', href: PromotionController.index().url },
        { title: 'Price rules', href: PriceRuleController.index().url },
    ] satisfies BreadcrumbItem[],
};
