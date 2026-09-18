import { router, useForm } from '@inertiajs/react';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InventoryItemController from '@/actions/App/Http/Controllers/Admin/Inventory/InventoryItemController';
import ServiceZoneStockLocationController from '@/actions/App/Http/Controllers/Admin/Inventory/ServiceZoneStockLocationController';
import StockLocationController from '@/actions/App/Http/Controllers/Admin/Inventory/StockLocationController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
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
import type { StockLocation } from '@/types/inventory';
import type { BreadcrumbItem } from '@/types';

type ZoneOption = {
    id: number;
    name: string;
    state_id: number;
    state?: { id: number; code: string; name: string };
};

type StockLocationFormData = {
    name: string;
    address: string;
    lat: string;
    lng: string;
};

function StockLocationFormDialog({
    stockLocation,
}: {
    stockLocation?: StockLocation;
}) {
    const isEdit = !!stockLocation;
    const [open, setOpen] = useState(false);

    const form = useForm<StockLocationFormData>({
        name: stockLocation?.name ?? '',
        address: stockLocation?.address ?? '',
        lat: stockLocation?.lat ?? '',
        lng: stockLocation?.lng ?? '',
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
            form.put(
                StockLocationController.update(stockLocation.id).url,
                options,
            );
        } else {
            form.post(StockLocationController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New stock location'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {isEdit
                            ? `Edit ${stockLocation.name}`
                            : 'New stock location'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
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
                        <Label htmlFor="address">Address</Label>
                        <Input
                            id="address"
                            value={form.data.address}
                            onChange={(e) =>
                                form.setData('address', e.target.value)
                            }
                        />
                        <InputError message={form.errors.address} />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="lat">Latitude</Label>
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
                            <Label htmlFor="lng">Longitude</Label>
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
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create location'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ManageZonesDialog({
    stockLocation,
    serviceZones,
}: {
    stockLocation: StockLocation;
    serviceZones: ZoneOption[];
}) {
    const [open, setOpen] = useState(false);
    const linkedIds = new Set(
        (stockLocation.service_zones ?? []).map((z) => z.id),
    );

    const toggle = (zoneId: number, linked: boolean) => {
        if (linked) {
            router.delete(
                ServiceZoneStockLocationController.destroy([
                    stockLocation.id,
                    zoneId,
                ]).url,
                { preserveScroll: true },
            );
        } else {
            router.post(
                ServiceZoneStockLocationController.store(stockLocation.id).url,
                { service_zone_id: zoneId },
                { preserveScroll: true },
            );
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Manage zones
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Zones served by {stockLocation.name}
                    </DialogTitle>
                </DialogHeader>

                <div className="max-h-80 space-y-2 overflow-y-auto">
                    {serviceZones.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No active service zones exist yet.
                        </p>
                    ) : (
                        serviceZones.map((zone) => {
                            const linked = linkedIds.has(zone.id);

                            return (
                                <label
                                    key={zone.id}
                                    className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                >
                                    <Checkbox
                                        checked={linked}
                                        onCheckedChange={() =>
                                            toggle(zone.id, linked)
                                        }
                                    />
                                    <span>
                                        {zone.name}
                                        {zone.state && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                ({zone.state.code})
                                            </span>
                                        )}
                                    </span>
                                </label>
                            );
                        })
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeleteStockLocationDialog({
    stockLocation,
}: {
    stockLocation: StockLocation;
}) {
    const [open, setOpen] = useState(false);

    const confirmDelete = () => {
        router.delete(StockLocationController.destroy(stockLocation.id).url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="text-destructive"
                >
                    Delete
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {stockLocation.name}?</DialogTitle>
                </DialogHeader>
                <p className="text-muted-foreground text-sm">
                    This permanently removes the location, its{' '}
                    {stockLocation.inventory_items_count ?? 0} stock row(s), and
                    its zone links. This cannot be undone.
                </p>
                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={confirmDelete}>
                        Delete location
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function StockLocationsIndex({
    stockLocations,
    serviceZones,
}: {
    stockLocations: StockLocation[];
    serviceZones: ZoneOption[];
}) {
    return (
        <>
            <Head title="Stock Locations" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Stock locations"
                        description="Warehouses/depots — manage their stock and which service zones each one backs."
                    />
                    <Can permission="inventory.manage">
                        <StockLocationFormDialog />
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>All locations</CardTitle>
                        <CardDescription>
                            Select a location to manage its per-variant stock
                            levels.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {stockLocations.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No stock locations yet.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Name
                                        </th>
                                        <th className="py-2 font-medium">
                                            Address
                                        </th>
                                        <th className="py-2 font-medium">
                                            Items
                                        </th>
                                        <th className="py-2 font-medium">
                                            Zones served
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {stockLocations.map((location) => (
                                        <tr
                                            key={location.id}
                                            className="border-b align-top last:border-0"
                                        >
                                            <td className="py-2">
                                                <Link
                                                    href={
                                                        InventoryItemController.index(
                                                            location.id,
                                                        ).url
                                                    }
                                                    className="font-medium hover:underline"
                                                >
                                                    {location.name}
                                                </Link>
                                            </td>
                                            <td className="text-muted-foreground py-2">
                                                {location.address}
                                            </td>
                                            <td className="py-2">
                                                {location.inventory_items_count ??
                                                    0}
                                            </td>
                                            <td className="py-2">
                                                <div className="flex flex-wrap gap-1">
                                                    {(
                                                        location.service_zones ??
                                                        []
                                                    ).length === 0 ? (
                                                        <span className="text-muted-foreground">
                                                            None
                                                        </span>
                                                    ) : (
                                                        location.service_zones?.map(
                                                            (zone) => (
                                                                <Badge
                                                                    key={
                                                                        zone.id
                                                                    }
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
                                                <Can permission="inventory.manage">
                                                    <ManageZonesDialog
                                                        stockLocation={location}
                                                        serviceZones={
                                                            serviceZones
                                                        }
                                                    />
                                                    <StockLocationFormDialog
                                                        stockLocation={location}
                                                    />
                                                    <DeleteStockLocationDialog
                                                        stockLocation={location}
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

StockLocationsIndex.layout = {
    breadcrumbs: [
        { title: 'Inventory', href: StockLocationController.index().url },
    ] satisfies BreadcrumbItem[],
};
