import { router, useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import CancellationPolicyController from '@/actions/App/Http/Controllers/Admin/Bookings/CancellationPolicyController';
import { BookingsSubNav } from '@/components/bookings-sub-nav';
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
    CANCELLATION_FEE_TYPE_OPTIONS,
    STATUS_OPTIONS,
    statusBadgeVariant,
} from '@/lib/enums';
import { centsToDollarsInput, dollarsInputToCents } from '@/lib/money';
import type {
    BookingLookup,
    CancellationFeeType,
    CancellationPolicy,
} from '@/types/bookings';
import type { Status } from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type PolicyFormData = {
    service_zone_id: string;
    notice_hours: string;
    fee_type: CancellationFeeType;
    fee_amount: string;
    fee_percent: string;
    status: Status;
};

const GLOBAL_VALUE = '__global__';

function PolicyFormDialog({
    policy,
    serviceZones,
}: {
    policy?: CancellationPolicy;
    serviceZones: BookingLookup[];
}) {
    const isEdit = !!policy;
    const [open, setOpen] = useState(false);

    const form = useForm<PolicyFormData>({
        service_zone_id: policy?.service_zone_id
            ? String(policy.service_zone_id)
            : GLOBAL_VALUE,
        notice_hours: policy ? String(policy.notice_hours) : '0',
        fee_type: policy?.fee_type ?? 'flat',
        fee_amount: centsToDollarsInput(policy?.fee_amount ?? 0),
        fee_percent: policy?.fee_percent ? String(policy.fee_percent) : '0',
        status: policy?.status ?? 'active',
    });

    const isFlat = form.data.fee_type === 'flat';

    const submit = () => {
        const payload = {
            service_zone_id:
                form.data.service_zone_id === GLOBAL_VALUE
                    ? null
                    : form.data.service_zone_id,
            notice_hours: form.data.notice_hours,
            fee_type: form.data.fee_type,
            fee_amount: isFlat
                ? dollarsInputToCents(form.data.fee_amount)
                : null,
            fee_percent: isFlat ? null : form.data.fee_percent,
            status: form.data.status,
        };

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!isEdit) {
                    form.reset();
                }
            },
        };

        form.transform(() => payload);

        if (isEdit) {
            form.put(
                CancellationPolicyController.update(policy.id).url,
                options,
            );
        } else {
            form.post(CancellationPolicyController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New policy'}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit policy' : 'New cancellation policy'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="service_zone_id">
                            Zone (or global default)
                        </Label>
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
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={GLOBAL_VALUE}>
                                    Global default (no zone)
                                </SelectItem>
                                {serviceZones.map((z) => (
                                    <SelectItem key={z.id} value={String(z.id)}>
                                        {z.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.service_zone_id} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="notice_hours">
                                Notice required (hours)
                            </Label>
                            <Input
                                id="notice_hours"
                                type="number"
                                min={0}
                                value={form.data.notice_hours}
                                onChange={(e) =>
                                    form.setData('notice_hours', e.target.value)
                                }
                            />
                            <InputError message={form.errors.notice_hours} />
                        </div>
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
                    </div>

                    {isFlat ? (
                        <div className="grid gap-2">
                            <Label htmlFor="fee_amount">Fee amount ($)</Label>
                            <Input
                                id="fee_amount"
                                type="number"
                                min={0}
                                step="0.01"
                                value={form.data.fee_amount}
                                onChange={(e) =>
                                    form.setData('fee_amount', e.target.value)
                                }
                            />
                            <InputError message={form.errors.fee_amount} />
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="fee_percent">
                                Fee percent (0-100)
                            </Label>
                            <Input
                                id="fee_percent"
                                type="number"
                                min={0}
                                max={100}
                                value={form.data.fee_percent}
                                onChange={(e) =>
                                    form.setData('fee_percent', e.target.value)
                                }
                            />
                            <InputError message={form.errors.fee_percent} />
                        </div>
                    )}

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
                            Only one active policy per zone (or one active
                            global default) is allowed at a time.
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create policy'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function CancellationPoliciesIndex({
    policies,
    serviceZones,
}: {
    policies: CancellationPolicy[];
    serviceZones: BookingLookup[];
}) {
    const destroy = (policy: CancellationPolicy) => {
        if (
            !confirm(
                `Delete the cancellation policy for ${policy.service_zone?.name ?? 'the global default'}?`,
            )
        ) {
            return;
        }

        router.delete(CancellationPolicyController.destroy(policy.id).url, {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Cancellation Policies" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Cancellation policies"
                    description="Notice-window/fee rules evaluated on every reschedule/cancel. The seeded global default is intentionally permissive (0 hours notice, no fee) until real numbers are confirmed."
                />

                <BookingsSubNav active="cancellation-policies" />

                <div className="flex justify-end">
                    <Can permission="bookings.manage">
                        <PolicyFormDialog serviceZones={serviceZones} />
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Policies</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {policies.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No cancellation policies yet.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Scope
                                        </th>
                                        <th className="py-2 font-medium">
                                            Notice
                                        </th>
                                        <th className="py-2 font-medium">
                                            Fee
                                        </th>
                                        <th className="py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {policies.map((policy) => (
                                        <tr
                                            key={policy.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2 font-medium">
                                                {policy.service_zone?.name ?? (
                                                    <Badge variant="secondary">
                                                        Global default
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {policy.notice_hours}h
                                            </td>
                                            <td className="text-muted-foreground py-2">
                                                {policy.fee_type === 'flat'
                                                    ? `$${((policy.fee_amount ?? 0) / 100).toFixed(2)} flat`
                                                    : `${policy.fee_percent ?? 0}%`}
                                            </td>
                                            <td className="py-2">
                                                <Badge
                                                    variant={statusBadgeVariant(
                                                        policy.status,
                                                    )}
                                                >
                                                    {policy.status}
                                                </Badge>
                                            </td>
                                            <td className="py-2 text-right">
                                                <Can permission="bookings.manage">
                                                    <div className="flex justify-end gap-2">
                                                        <PolicyFormDialog
                                                            policy={policy}
                                                            serviceZones={
                                                                serviceZones
                                                            }
                                                        />
                                                        {policy.service_zone_id !==
                                                            null && (
                                                            <Button
                                                                variant="destructive"
                                                                size="sm"
                                                                onClick={() =>
                                                                    destroy(
                                                                        policy,
                                                                    )
                                                                }
                                                            >
                                                                Delete
                                                            </Button>
                                                        )}
                                                    </div>
                                                </Can>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CancellationPoliciesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Bookings',
            href: CancellationPolicyController.index().url,
        },
        {
            title: 'Cancellation policies',
            href: CancellationPolicyController.index().url,
        },
    ] satisfies BreadcrumbItem[],
};
