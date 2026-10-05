import { useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import TechnicianShiftController from '@/actions/App/Http/Controllers/Admin/Bookings/TechnicianShiftController';
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
import { STATUS_OPTIONS, statusBadgeVariant } from '@/lib/enums';
import type { BookingLookup, TechnicianShift } from '@/types/bookings';
import type { Status } from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type ShiftFormData = {
    technician_id: string;
    van_id: string;
    service_zone_id: string;
    date: string;
    shift_start: string;
    shift_end: string;
    status: Status;
};

function todayIsoDate(): string {
    return new Date().toISOString().slice(0, 10);
}

function ShiftFormDialog({
    shift,
    technicians,
    vans,
    serviceZones,
}: {
    shift?: TechnicianShift;
    technicians: BookingLookup[];
    vans: (BookingLookup & { rego: string })[];
    serviceZones: BookingLookup[];
}) {
    const isEdit = !!shift;
    const [open, setOpen] = useState(false);
    const [clientError, setClientError] = useState<string | null>(null);

    const form = useForm<ShiftFormData>({
        technician_id: shift ? String(shift.technician_id) : '',
        van_id: shift ? String(shift.van_id) : '',
        service_zone_id: shift ? String(shift.service_zone_id) : '',
        date: shift?.date ?? todayIsoDate(),
        shift_start: shift?.shift_start.slice(0, 5) ?? '08:00',
        shift_end: shift?.shift_end.slice(0, 5) ?? '16:00',
        status: shift?.status ?? 'active',
    });

    const submit = () => {
        // Client-side backstop for the DB CHECK constraint / server
        // `after:shift_start` rule — surfaced immediately, without a round
        // trip, per the task brief's explicit requirement.
        if (form.data.shift_end <= form.data.shift_start) {
            setClientError('Shift end must be after shift start.');
            return;
        }

        setClientError(null);

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
            form.put(TechnicianShiftController.update(shift.id).url, options);
        } else {
            form.post(TechnicianShiftController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New shift'}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit shift' : 'New shift'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="technician_id">Technician</Label>
                        <Select
                            value={form.data.technician_id}
                            onValueChange={(v) =>
                                form.setData('technician_id', v)
                            }
                        >
                            <SelectTrigger
                                id="technician_id"
                                className="w-full"
                            >
                                <SelectValue placeholder="Select a technician" />
                            </SelectTrigger>
                            <SelectContent>
                                {technicians.map((t) => (
                                    <SelectItem key={t.id} value={String(t.id)}>
                                        {t.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.technician_id} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="van_id">Van</Label>
                            <Select
                                value={form.data.van_id}
                                onValueChange={(v) => form.setData('van_id', v)}
                            >
                                <SelectTrigger id="van_id" className="w-full">
                                    <SelectValue placeholder="Select a van" />
                                </SelectTrigger>
                                <SelectContent>
                                    {vans.map((v) => (
                                        <SelectItem
                                            key={v.id}
                                            value={String(v.id)}
                                        >
                                            {v.name} ({v.rego})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.van_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="service_zone_id">
                                Service zone
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
                                    <SelectValue placeholder="Select a zone" />
                                </SelectTrigger>
                                <SelectContent>
                                    {serviceZones.map((z) => (
                                        <SelectItem
                                            key={z.id}
                                            value={String(z.id)}
                                        >
                                            {z.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.service_zone_id} />
                        </div>
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="date">Date</Label>
                            <Input
                                id="date"
                                type="date"
                                value={form.data.date}
                                onChange={(e) =>
                                    form.setData('date', e.target.value)
                                }
                            />
                            <InputError message={form.errors.date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="shift_start">Start</Label>
                            <Input
                                id="shift_start"
                                type="time"
                                value={form.data.shift_start}
                                onChange={(e) =>
                                    form.setData('shift_start', e.target.value)
                                }
                            />
                            <InputError message={form.errors.shift_start} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="shift_end">End</Label>
                            <Input
                                id="shift_end"
                                type="time"
                                value={form.data.shift_end}
                                onChange={(e) =>
                                    form.setData('shift_end', e.target.value)
                                }
                            />
                            <InputError message={form.errors.shift_end} />
                        </div>
                    </div>
                    {clientError && (
                        <p className="text-destructive text-sm">
                            {clientError}
                        </p>
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
                            A sick-day/roster change is usually "inactive", not
                            deleted — shift history feeds reporting.
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create shift'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function ShiftsIndex({
    shifts,
    technicians,
    vans,
    serviceZones,
}: {
    shifts: TechnicianShift[];
    technicians: BookingLookup[];
    vans: (BookingLookup & { rego: string })[];
    serviceZones: BookingLookup[];
}) {
    return (
        <>
            <Head title="Technician Shifts" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Technician shifts"
                    description="The capacity source of truth the dispatch board and the booking engine both read — shown 7 days back through 60 days ahead."
                />

                <BookingsSubNav active="shifts" />

                <div className="flex justify-end">
                    <Can permission="bookings.manage">
                        <ShiftFormDialog
                            technicians={technicians}
                            vans={vans}
                            serviceZones={serviceZones}
                        />
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Shifts</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {shifts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No shifts in this window.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Date
                                        </th>
                                        <th className="py-2 font-medium">
                                            Technician
                                        </th>
                                        <th className="py-2 font-medium">
                                            Van
                                        </th>
                                        <th className="py-2 font-medium">
                                            Zone
                                        </th>
                                        <th className="py-2 font-medium">
                                            Hours
                                        </th>
                                        <th className="py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {shifts.map((shift) => (
                                        <tr
                                            key={shift.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2 font-medium">
                                                {shift.date}
                                            </td>
                                            <td className="py-2">
                                                {shift.technician?.name}
                                            </td>
                                            <td className="text-muted-foreground py-2">
                                                {shift.van?.name} (
                                                {shift.van?.rego})
                                            </td>
                                            <td className="text-muted-foreground py-2">
                                                {shift.service_zone?.name}
                                            </td>
                                            <td className="py-2">
                                                {shift.shift_start.slice(0, 5)}–
                                                {shift.shift_end.slice(0, 5)}
                                            </td>
                                            <td className="py-2">
                                                <Badge
                                                    variant={statusBadgeVariant(
                                                        shift.status,
                                                    )}
                                                >
                                                    {shift.status}
                                                </Badge>
                                            </td>
                                            <td className="py-2 text-right">
                                                <Can permission="bookings.manage">
                                                    <ShiftFormDialog
                                                        shift={shift}
                                                        technicians={
                                                            technicians
                                                        }
                                                        vans={vans}
                                                        serviceZones={
                                                            serviceZones
                                                        }
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
            </div>
        </>
    );
}

ShiftsIndex.layout = {
    breadcrumbs: [
        { title: 'Bookings', href: TechnicianShiftController.index().url },
        { title: 'Shifts', href: TechnicianShiftController.index().url },
    ] satisfies BreadcrumbItem[],
};
