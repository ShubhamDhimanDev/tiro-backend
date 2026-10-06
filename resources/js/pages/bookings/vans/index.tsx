import { ListToolbar, useListFilter } from '@/components/list-toolbar';
import { useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import VanController from '@/actions/App/Http/Controllers/Admin/Bookings/VanController';
import { BookingsSubNav } from '@/components/bookings-sub-nav';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { Van } from '@/types/bookings';
import type { StockLocation } from '@/types/inventory';
import type { Status } from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type VanFormData = {
    rego: string;
    name: string;
    home_stock_location_id: string;
    has_alignment_equipment: boolean;
    max_jobs_per_day: string;
    status: Status;
};

function VanFormDialog({
    van,
    stockLocations,
}: {
    van?: Van;
    stockLocations: Pick<StockLocation, 'id' | 'name'>[];
}) {
    const isEdit = !!van;
    const [open, setOpen] = useState(false);

    const form = useForm<VanFormData>({
        rego: van?.rego ?? '',
        name: van?.name ?? '',
        home_stock_location_id: van ? String(van.home_stock_location_id) : '',
        has_alignment_equipment: van?.has_alignment_equipment ?? false,
        max_jobs_per_day: van ? String(van.max_jobs_per_day) : '8',
        status: van?.status ?? 'active',
    });

    const submit = () => {
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
            form.put(VanController.update(van.id).url, options);
        } else {
            form.post(VanController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New van'}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? `Edit ${van.name}` : 'New van'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="rego">Rego</Label>
                            <Input
                                id="rego"
                                value={form.data.rego}
                                onChange={(e) =>
                                    form.setData('rego', e.target.value)
                                }
                            />
                            <InputError message={form.errors.rego} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            <InputError message={form.errors.name} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="home_stock_location_id">
                            Home stock location
                        </Label>
                        <Select
                            value={form.data.home_stock_location_id}
                            onValueChange={(v) =>
                                form.setData('home_stock_location_id', v)
                            }
                        >
                            <SelectTrigger
                                id="home_stock_location_id"
                                className="w-full"
                            >
                                <SelectValue placeholder="Select a location" />
                            </SelectTrigger>
                            <SelectContent>
                                {stockLocations.map((loc) => (
                                    <SelectItem
                                        key={loc.id}
                                        value={String(loc.id)}
                                    >
                                        {loc.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            message={form.errors.home_stock_location_id}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="max_jobs_per_day">
                                Max jobs / day
                            </Label>
                            <Input
                                id="max_jobs_per_day"
                                type="number"
                                min={1}
                                max={255}
                                value={form.data.max_jobs_per_day}
                                onChange={(e) =>
                                    form.setData(
                                        'max_jobs_per_day',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.max_jobs_per_day}
                            />
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
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.status} />
                        </div>
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={form.data.has_alignment_equipment}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'has_alignment_equipment',
                                    checked === true,
                                )
                            }
                        />
                        Has wheel alignment equipment
                    </label>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create van'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function VansIndex({
    vans,
    stockLocations,
}: {
    vans: Van[];
    stockLocations: Pick<StockLocation, 'id' | 'name'>[];
}) {
    const list = useListFilter(vans, {
        placeholder: 'Search vans by name or rego…',
        searchText: (i) => [i.name, i.rego, i.home_stock_location?.name],
        filters: {
            Status: { label: 'Status', get: (i) => i.status },
        },
    });

    return (
        <>
            <Head title="Vans" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Vans"
                    description="Service vehicles — the daily job-cap unit the booking engine schedules against."
                />

                <BookingsSubNav active="vans" />

                <div className="flex justify-end">
                    <Can permission="bookings.manage">
                        <VanFormDialog stockLocations={stockLocations} />
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Vans</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ListToolbar {...list.toolbarProps} />
                        {vans.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No vans yet.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Rego
                                        </th>
                                        <th className="py-2 font-medium">
                                            Name
                                        </th>
                                        <th className="py-2 font-medium">
                                            Home location
                                        </th>
                                        <th className="py-2 font-medium">
                                            Alignment
                                        </th>
                                        <th className="py-2 font-medium">
                                            Max jobs/day
                                        </th>
                                        <th className="py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {list.filtered.map((van) => (
                                        <tr
                                            key={van.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2 font-medium">
                                                {van.rego}
                                            </td>
                                            <td className="py-2">{van.name}</td>
                                            <td className="text-muted-foreground py-2">
                                                {van.home_stock_location
                                                    ?.name ?? '—'}
                                            </td>
                                            <td className="py-2">
                                                {van.has_alignment_equipment ? (
                                                    <Badge variant="default">
                                                        Yes
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        No
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {van.max_jobs_per_day}
                                            </td>
                                            <td className="py-2">
                                                <Badge
                                                    variant={statusBadgeVariant(
                                                        van.status,
                                                    )}
                                                >
                                                    {van.status}
                                                </Badge>
                                            </td>
                                            <td className="py-2 text-right">
                                                <Can permission="bookings.manage">
                                                    <VanFormDialog
                                                        van={van}
                                                        stockLocations={
                                                            stockLocations
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

VansIndex.layout = {
    breadcrumbs: [
        { title: 'Bookings', href: VanController.index().url },
        { title: 'Vans', href: VanController.index().url },
    ] satisfies BreadcrumbItem[],
};
