import { ListToolbar, useListFilter } from '@/components/list-toolbar';
import { router, useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ServiceZoneController from '@/actions/App/Http/Controllers/Admin/Locations/ServiceZoneController';
import ServiceZoneSuburbController from '@/actions/App/Http/Controllers/Admin/Locations/ServiceZoneSuburbController';
import { Can } from '@/components/can';
import InputError from '@/components/input-error';
import { OperatingHoursEditor } from '@/components/operating-hours-editor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    SERVICE_ZONE_TYPE_OPTIONS,
    STATUS_OPTIONS,
    statusBadgeVariant,
} from '@/lib/enums';
import { CLOSED_WEEK } from '@/types/locations';
import type {
    ServiceZone,
    ServiceZoneType,
    State,
    Suburb,
} from '@/types/locations';
import type { Status } from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type ServiceZoneFormData = {
    name: string;
    state_id: string;
    type: ServiceZoneType;
    origin_lat: string;
    origin_lng: string;
    radius_km: string;
    operating_hours: typeof CLOSED_WEEK;
    priority: string;
    status: Status;
};

/** Suburbs currently in `zone`, mapped to the *other* active zones they also belong to. */
function overlapsFor(
    zone: ServiceZone | undefined,
    stateSuburbs: Suburb[],
    allZones: ServiceZone[],
): Map<number, string[]> {
    const overlaps = new Map<number, string[]>();

    for (const suburb of stateSuburbs) {
        const otherActiveZones = allZones
            .filter((z) => z.id !== zone?.id)
            .filter((z) => z.type === 'suburb_list' && z.status === 'active')
            .filter((z) => z.suburbs?.some((s) => s.id === suburb.id))
            .map((z) => z.name);

        if (otherActiveZones.length > 0) {
            overlaps.set(suburb.id, otherActiveZones);
        }
    }

    return overlaps;
}

function SuburbMembershipEditor({
    zone,
    stateSuburbs,
    allZones,
}: {
    zone: ServiceZone;
    stateSuburbs: Suburb[];
    allZones: ServiceZone[];
}) {
    const memberIds = new Set((zone.suburbs ?? []).map((s) => s.id));
    const overlaps = useMemo(
        () => overlapsFor(zone, stateSuburbs, allZones),
        [zone, stateSuburbs, allZones],
    );

    const toggle = (suburbId: number, isMember: boolean) => {
        if (isMember) {
            router.delete(
                ServiceZoneSuburbController.destroy([zone.id, suburbId]).url,
                { preserveScroll: true },
            );
        } else {
            router.post(
                ServiceZoneSuburbController.store(zone.id).url,
                { suburb_id: suburbId },
                { preserveScroll: true },
            );
        }
    };

    return (
        <div className="grid gap-2">
            <Label>Suburbs in this zone</Label>
            <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border p-2">
                {stateSuburbs.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No suburbs exist yet for this state.
                    </p>
                ) : (
                    stateSuburbs.map((suburb) => {
                        const isMember = memberIds.has(suburb.id);
                        const overlapZones = overlaps.get(suburb.id);

                        return (
                            <label
                                key={suburb.id}
                                className="flex items-center gap-2 rounded px-2 py-1 text-sm"
                            >
                                <Checkbox
                                    checked={isMember}
                                    onCheckedChange={() =>
                                        toggle(suburb.id, isMember)
                                    }
                                />
                                <span>
                                    {suburb.name} ({suburb.postcode})
                                </span>
                                {overlapZones && (
                                    <Badge
                                        variant="secondary"
                                        className="ml-auto"
                                    >
                                        Also in: {overlapZones.join(', ')}
                                    </Badge>
                                )}
                            </label>
                        );
                    })
                )}
            </div>
            <p className="text-muted-foreground text-xs">
                A suburb belonging to more than one active zone isn't an error —
                resolution falls back to nearest-radius, then zone priority. The
                badge above just makes that visible before it happens.
            </p>
        </div>
    );
}

function ServiceZoneFormDialog({
    zone,
    states,
    suburbs,
    allZones,
}: {
    zone?: ServiceZone;
    states: State[];
    suburbs: Suburb[];
    allZones: ServiceZone[];
}) {
    const isEdit = !!zone;
    const [open, setOpen] = useState(false);

    const form = useForm<ServiceZoneFormData>({
        name: zone?.name ?? '',
        state_id: zone ? String(zone.state_id) : '',
        type: zone?.type ?? 'radius',
        origin_lat: zone?.origin_lat ?? '',
        origin_lng: zone?.origin_lng ?? '',
        radius_km: zone?.radius_km ?? '',
        operating_hours: zone?.operating_hours ?? CLOSED_WEEK,
        priority: zone ? String(zone.priority) : '0',
        status: zone?.status ?? 'active',
    });

    const isRadius = form.data.type === 'radius';

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
            form.put(ServiceZoneController.update(zone.id).url, options);
        } else {
            form.post(ServiceZoneController.store().url, options);
        }
    };

    const stateSuburbs = suburbs.filter(
        (s) => String(s.state_id) === form.data.state_id,
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New zone'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? `Edit ${zone.name}` : 'New service zone'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid grid-cols-2 gap-4">
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
                        <div className="grid gap-2">
                            <Label htmlFor="state_id">State</Label>
                            <Select
                                value={form.data.state_id}
                                onValueChange={(v) =>
                                    form.setData('state_id', v)
                                }
                            >
                                <SelectTrigger id="state_id" className="w-full">
                                    <SelectValue placeholder="Select a state" />
                                </SelectTrigger>
                                <SelectContent>
                                    {states.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.name} ({s.code})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.state_id} />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="type">Type</Label>
                            <Select
                                value={form.data.type}
                                onValueChange={(v) =>
                                    form.setData('type', v as ServiceZoneType)
                                }
                            >
                                <SelectTrigger id="type" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {SERVICE_ZONE_TYPE_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="priority">
                                Priority (overlap tie-break, higher wins)
                            </Label>
                            <Input
                                id="priority"
                                type="number"
                                min={0}
                                value={form.data.priority}
                                onChange={(e) =>
                                    form.setData('priority', e.target.value)
                                }
                            />
                            <InputError message={form.errors.priority} />
                        </div>
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="origin_lat">
                                Origin latitude{isRadius && ' *'}
                            </Label>
                            <Input
                                id="origin_lat"
                                value={form.data.origin_lat}
                                onChange={(e) =>
                                    form.setData('origin_lat', e.target.value)
                                }
                            />
                            <InputError message={form.errors.origin_lat} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="origin_lng">
                                Origin longitude{isRadius && ' *'}
                            </Label>
                            <Input
                                id="origin_lng"
                                value={form.data.origin_lng}
                                onChange={(e) =>
                                    form.setData('origin_lng', e.target.value)
                                }
                            />
                            <InputError message={form.errors.origin_lng} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="radius_km">
                                Radius (km){isRadius && ' *'}
                            </Label>
                            <Input
                                id="radius_km"
                                value={form.data.radius_km}
                                onChange={(e) =>
                                    form.setData('radius_km', e.target.value)
                                }
                            />
                            <InputError message={form.errors.radius_km} />
                        </div>
                    </div>
                    {!isRadius && (
                        <p className="text-muted-foreground -mt-2 text-xs">
                            Optional for suburb-list zones — only used as a
                            display centroid for a future map view, not for
                            resolution.
                        </p>
                    )}

                    <div className="grid gap-2">
                        <Label>Operating hours</Label>
                        <OperatingHoursEditor
                            value={form.data.operating_hours}
                            onChange={(v) => form.setData('operating_hours', v)}
                            errors={
                                form.errors as Record<
                                    string,
                                    string | undefined
                                >
                            }
                        />
                        <InputError message={form.errors.operating_hours} />
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

                    {isEdit && zone.type === 'suburb_list' && (
                        <SuburbMembershipEditor
                            zone={zone}
                            stateSuburbs={stateSuburbs}
                            allZones={allZones}
                        />
                    )}
                    {!isEdit && form.data.type === 'suburb_list' && (
                        <p className="text-muted-foreground text-xs">
                            Save the zone first, then reopen it here to assign
                            suburbs.
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create zone'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function ServiceZonesIndex({
    serviceZones,
    states,
    suburbs,
}: {
    serviceZones: ServiceZone[];
    states: State[];
    suburbs: Suburb[];
}) {
    const list = useListFilter(serviceZones, {
        placeholder: 'Search zones by name or state…',
        searchText: (i) => [i.name, i.state?.name, i.state?.code],
        filters: {
            Status: { label: 'Status', get: (i) => i.status },
            Type: { label: 'Type', get: (i) => i.type },
            State: { label: 'State', get: (i) => i.state?.code },
        },
    });

    return (
        <>
            <Head title="Service Zones" />

            <div className="flex justify-end">
                <Can permission="locations.manage">
                    <ServiceZoneFormDialog
                        states={states}
                        suburbs={suburbs}
                        allZones={serviceZones}
                    />
                </Can>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Service zones</CardTitle>
                    <CardDescription>
                        Radius zones resolve by distance from an origin point;
                        suburb-list zones resolve by explicit membership.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ListToolbar {...list.toolbarProps} />
                    {serviceZones.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No service zones yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">Name</th>
                                    <th className="py-2 font-medium">State</th>
                                    <th className="py-2 font-medium">Type</th>
                                    <th className="py-2 font-medium">
                                        Coverage
                                    </th>
                                    <th className="py-2 font-medium">
                                        Priority
                                    </th>
                                    <th className="py-2 font-medium">Status</th>
                                    <th className="py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {list.filtered.map((zone) => (
                                    <tr
                                        key={zone.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 font-medium">
                                            {zone.name}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {zone.state?.code}
                                        </td>
                                        <td className="py-2 capitalize">
                                            {zone.type.replace('_', ' ')}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {zone.type === 'radius'
                                                ? `${zone.radius_km ?? '—'} km`
                                                : `${zone.suburbs?.length ?? 0} suburb(s)`}
                                        </td>
                                        <td className="py-2">
                                            {zone.priority}
                                        </td>
                                        <td className="py-2">
                                            <Badge
                                                variant={statusBadgeVariant(
                                                    zone.status,
                                                )}
                                            >
                                                {zone.status}
                                            </Badge>
                                        </td>
                                        <td className="py-2 text-right">
                                            <Can permission="locations.manage">
                                                <ServiceZoneFormDialog
                                                    zone={zone}
                                                    states={states}
                                                    suburbs={suburbs}
                                                    allZones={serviceZones}
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

ServiceZonesIndex.layout = {
    breadcrumbs: [
        { title: 'Locations', href: ServiceZoneController.index().url },
        { title: 'Service zones', href: ServiceZoneController.index().url },
    ] satisfies BreadcrumbItem[],
};
