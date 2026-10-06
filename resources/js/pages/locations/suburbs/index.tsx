import { ListToolbar, useListFilter } from '@/components/list-toolbar';
import { router, useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ServiceZoneSuburbController from '@/actions/App/Http/Controllers/Admin/Locations/ServiceZoneSuburbController';
import SuburbController from '@/actions/App/Http/Controllers/Admin/Locations/SuburbController';
import { Can } from '@/components/can';
import InputError from '@/components/input-error';
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
import type { State, Suburb, SuburbZoneSummary } from '@/types/locations';
import type { BreadcrumbItem } from '@/types';

type SuburbFormData = {
    name: string;
    state_id: string;
    postcode: string;
    lat: string;
    lng: string;
};

function ZoneMembershipEditor({
    suburb,
    zonesInState,
}: {
    suburb: Suburb;
    zonesInState: SuburbZoneSummary[];
}) {
    const memberIds = new Set((suburb.service_zones ?? []).map((z) => z.id));

    const toggle = (zoneId: number, isMember: boolean) => {
        if (isMember) {
            router.delete(
                ServiceZoneSuburbController.destroy([zoneId, suburb.id]).url,
                { preserveScroll: true },
            );
        } else {
            router.post(
                ServiceZoneSuburbController.store(zoneId).url,
                { suburb_id: suburb.id },
                { preserveScroll: true },
            );
        }
    };

    const activeMemberships = (suburb.service_zones ?? []).filter(
        (z) => z.status === 'active',
    );

    return (
        <div className="grid gap-2">
            <Label>Suburb-list zone membership</Label>
            {activeMemberships.length > 1 && (
                <p className="text-destructive text-xs">
                    Already in {activeMemberships.length} active zones —{' '}
                    {activeMemberships.map((z) => z.name).join(', ')}. Not an
                    error, but overlap resolution (nearest-radius, then
                    priority) will apply.
                </p>
            )}
            <div className="max-h-48 space-y-1 overflow-y-auto rounded-md border p-2">
                {zonesInState.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No suburb-list zones exist yet for this state.
                    </p>
                ) : (
                    zonesInState.map((zone) => (
                        <label
                            key={zone.id}
                            className="flex items-center gap-2 rounded px-2 py-1 text-sm"
                        >
                            <Checkbox
                                checked={memberIds.has(zone.id)}
                                onCheckedChange={() =>
                                    toggle(zone.id, memberIds.has(zone.id))
                                }
                            />
                            <span>{zone.name}</span>
                            {zone.status !== 'active' && (
                                <Badge variant="outline">{zone.status}</Badge>
                            )}
                        </label>
                    ))
                )}
            </div>
        </div>
    );
}

function SuburbFormDialog({
    suburb,
    states,
    suburbListZones,
}: {
    suburb?: Suburb;
    states: State[];
    suburbListZones: SuburbZoneSummary[];
}) {
    const isEdit = !!suburb;
    const [open, setOpen] = useState(false);

    const form = useForm<SuburbFormData>({
        name: suburb?.name ?? '',
        state_id: suburb ? String(suburb.state_id) : '',
        postcode: suburb?.postcode ?? '',
        lat: suburb?.lat ?? '',
        lng: suburb?.lng ?? '',
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
            form.put(SuburbController.update(suburb.id).url, options);
        } else {
            form.post(SuburbController.store().url, options);
        }
    };

    const zonesInState = suburbListZones.filter(
        (z) => String(z.state_id) === form.data.state_id,
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New suburb'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? `Edit ${suburb.name}` : 'New suburb'}
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

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="postcode">Postcode</Label>
                            <Input
                                id="postcode"
                                maxLength={4}
                                value={form.data.postcode}
                                onChange={(e) =>
                                    form.setData('postcode', e.target.value)
                                }
                            />
                            <InputError message={form.errors.postcode} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="lat">Latitude *</Label>
                            <Input
                                id="lat"
                                value={form.data.lat}
                                onChange={(e) =>
                                    form.setData('lat', e.target.value)
                                }
                            />
                            <InputError message={form.errors.lat} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="lng">Longitude *</Label>
                            <Input
                                id="lng"
                                value={form.data.lng}
                                onChange={(e) =>
                                    form.setData('lng', e.target.value)
                                }
                            />
                            <InputError message={form.errors.lng} />
                        </div>
                    </div>
                    <p className="text-muted-foreground -mt-2 text-xs">
                        Coordinates are required — a missing centroid silently
                        breaks radius-zone matching for this suburb.
                    </p>

                    {isEdit ? (
                        <ZoneMembershipEditor
                            suburb={suburb}
                            zonesInState={zonesInState}
                        />
                    ) : (
                        <p className="text-muted-foreground text-xs">
                            Save the suburb first, then reopen it here to assign
                            it to suburb-list zones.
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create suburb'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function SuburbsIndex({
    suburbs,
    states,
    suburbListZones,
}: {
    suburbs: Suburb[];
    states: State[];
    suburbListZones: SuburbZoneSummary[];
}) {
    const list = useListFilter(suburbs, {
        placeholder: 'Search suburbs by name or postcode…',
        searchText: (i) => [i.name, i.postcode, i.state?.code],
        filters: {
            State: { label: 'State', get: (i) => i.state?.code },
        },
    });

    return (
        <>
            <Head title="Suburbs" />

            <div className="flex justify-end">
                <Can permission="locations.manage">
                    <SuburbFormDialog
                        states={states}
                        suburbListZones={suburbListZones}
                    />
                </Can>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Suburbs</CardTitle>
                    <CardDescription>
                        Every suburb's coordinates back radius-zone matching;
                        badges show suburb-list zone membership.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ListToolbar {...list.toolbarProps} />
                    {suburbs.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No suburbs yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">Name</th>
                                    <th className="py-2 font-medium">State</th>
                                    <th className="py-2 font-medium">
                                        Postcode
                                    </th>
                                    <th className="py-2 font-medium">
                                        Coordinates
                                    </th>
                                    <th className="py-2 font-medium">Zones</th>
                                    <th className="py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {list.filtered.map((suburb) => (
                                    <tr
                                        key={suburb.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 font-medium">
                                            {suburb.name}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {suburb.state?.code}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {suburb.postcode}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {suburb.lat}, {suburb.lng}
                                        </td>
                                        <td className="py-2">
                                            <div className="flex flex-wrap gap-1">
                                                {(suburb.service_zones ?? [])
                                                    .length === 0 ? (
                                                    <span className="text-muted-foreground">
                                                        None
                                                    </span>
                                                ) : (
                                                    suburb.service_zones?.map(
                                                        (zone) => (
                                                            <Badge
                                                                key={zone.id}
                                                                variant="outline"
                                                            >
                                                                {zone.name}
                                                            </Badge>
                                                        ),
                                                    )
                                                )}
                                            </div>
                                        </td>
                                        <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                            <Can permission="locations.manage">
                                                <SuburbFormDialog
                                                    suburb={suburb}
                                                    states={states}
                                                    suburbListZones={
                                                        suburbListZones
                                                    }
                                                />
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="text-destructive"
                                                    onClick={() =>
                                                        router.delete(
                                                            SuburbController.destroy(
                                                                suburb.id,
                                                            ).url,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Delete
                                                </Button>
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

SuburbsIndex.layout = {
    breadcrumbs: [
        { title: 'Locations', href: SuburbController.index().url },
        { title: 'Suburbs', href: SuburbController.index().url },
    ] satisfies BreadcrumbItem[],
};
