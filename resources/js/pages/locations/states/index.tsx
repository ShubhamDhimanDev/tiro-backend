import { router, useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import StateController from '@/actions/App/Http/Controllers/Admin/Locations/StateController';
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
import { STATUS_OPTIONS, statusBadgeVariant } from '@/lib/enums';
import type { State } from '@/types/locations';
import type { Status } from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type StateFormData = {
    code: string;
    name: string;
    status: Status;
};

function StateFormDialog({ state }: { state?: State }) {
    const isEdit = !!state;
    const [open, setOpen] = useState(false);

    const form = useForm<StateFormData>({
        code: state?.code ?? '',
        name: state?.name ?? '',
        status: state?.status ?? 'active',
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
            form.put(StateController.update(state.id).url, options);
        } else {
            form.post(StateController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New state'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? `Edit ${state.name}` : 'New state/territory'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                maxLength={3}
                                className="uppercase"
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData(
                                        'code',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                            <InputError message={form.errors.code} />
                        </div>
                        <div className="col-span-2 grid gap-2">
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
                    </div>

                    {!isEdit && (
                        <p className="text-muted-foreground text-xs">
                            New states are created inactive — activate them
                            separately once their zones/suburbs are ready.
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create state'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ToggleActiveDialog({ state }: { state: State }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const nextValue = !state.is_active;

    const confirm = () => {
        setProcessing(true);
        router.patch(
            StateController.toggleActive(state.id).url,
            { is_active: nextValue },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setOpen(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={state.is_active ? 'outline' : 'default'}
                    size="sm"
                >
                    {state.is_active ? 'Deactivate' : 'Activate'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {nextValue ? 'Activate' : 'Deactivate'} {state.name}?
                    </DialogTitle>
                </DialogHeader>
                <p className="text-muted-foreground text-sm">
                    {nextValue
                        ? `This opens ${state.name} up for storefront serviceability — its active zones and suburbs become part of live geography.`
                        : `This removes ${state.name} from live geography. Its zones/suburbs stay configured, just not offered, until reactivated.`}
                </p>
                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                    <Button disabled={processing} onClick={confirm}>
                        {nextValue ? 'Activate' : 'Deactivate'} {state.code}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function StatesIndex({ states }: { states: State[] }) {
    return (
        <>
            <Head title="States" />

            <div className="flex justify-end">
                <Can permission="locations.manage">
                    <StateFormDialog />
                </Can>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>States & territories</CardTitle>
                    <CardDescription>
                        Every state exists here whether or not it's live —
                        "Active" below is the actual storefront-geography lever.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="py-2 font-medium">Code</th>
                                <th className="py-2 font-medium">Name</th>
                                <th className="py-2 font-medium">Zones</th>
                                <th className="py-2 font-medium">Suburbs</th>
                                <th className="py-2 font-medium">Status</th>
                                <th className="py-2 font-medium">Live</th>
                                <th className="py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {states.map((state) => (
                                <tr
                                    key={state.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="py-2 font-medium">
                                        {state.code}
                                    </td>
                                    <td className="py-2">{state.name}</td>
                                    <td className="py-2">
                                        {state.service_zones_count ?? 0}
                                    </td>
                                    <td className="py-2">
                                        {state.suburbs_count ?? 0}
                                    </td>
                                    <td className="py-2">
                                        <Badge
                                            variant={statusBadgeVariant(
                                                state.status,
                                            )}
                                        >
                                            {state.status}
                                        </Badge>
                                    </td>
                                    <td className="py-2">
                                        <Badge
                                            variant={
                                                state.is_active
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {state.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                    </td>
                                    <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                        <Can permission="locations.manage">
                                            <ToggleActiveDialog state={state} />
                                            <StateFormDialog state={state} />
                                        </Can>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </>
    );
}

StatesIndex.layout = {
    breadcrumbs: [
        { title: 'Locations', href: StateController.index().url },
        { title: 'States', href: StateController.index().url },
    ] satisfies BreadcrumbItem[],
};
