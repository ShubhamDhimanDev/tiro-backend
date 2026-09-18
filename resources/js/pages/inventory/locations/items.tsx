import { router, useForm } from '@inertiajs/react';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InventoryItemController from '@/actions/App/Http/Controllers/Admin/Inventory/InventoryItemController';
import StockLocationController from '@/actions/App/Http/Controllers/Admin/Inventory/StockLocationController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import type { InventoryItem, StockLocation } from '@/types/inventory';
import type { BreadcrumbItem } from '@/types';

type AvailableVariant = {
    id: number;
    tyre_model_id: number;
    sku: string;
    width: number;
    profile: number;
    rim_diameter: number;
    load_index: string;
    speed_rating: string;
    tyre_model: {
        id: number;
        name: string;
        brand: { id: number; name: string };
    };
};

function variantLabel(v: {
    sku: string;
    width: number;
    profile: number;
    rim_diameter: number;
    tyre_model: { name: string; brand: { name: string } };
}): string {
    return `${v.tyre_model.brand.name} ${v.tyre_model.name} — ${v.width}/${v.profile}R${v.rim_diameter} (${v.sku})`;
}

type QtyFormData = {
    tyre_variant_id: string;
    qty_on_hand: string;
    qty_reserved: string;
    reorder_point: string;
};

function AddInventoryItemDialog({
    stockLocation,
    availableVariants,
    stockedVariantIds,
}: {
    stockLocation: StockLocation;
    availableVariants: AvailableVariant[];
    stockedVariantIds: Set<number>;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<AvailableVariant | null>(null);

    const form = useForm<QtyFormData>({
        tyre_variant_id: '',
        qty_on_hand: '0',
        qty_reserved: '0',
        reorder_point: '0',
    });

    const candidates = useMemo(() => {
        const term = search.trim().toLowerCase();

        return availableVariants
            .filter((v) => !stockedVariantIds.has(v.id))
            .filter(
                (v) =>
                    term === '' || variantLabel(v).toLowerCase().includes(term),
            )
            .slice(0, 20);
    }, [availableVariants, stockedVariantIds, search]);

    const reset = () => {
        setSelected(null);
        setSearch('');
        form.reset();
    };

    const submit = () => {
        form.post(InventoryItemController.store(stockLocation.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                reset();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    reset();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button>Add stock row</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add a stocked variant</DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    {selected ? (
                        <div className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                            <span>{variantLabel(selected)}</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setSelected(null);
                                    form.setData('tyre_variant_id', '');
                                }}
                            >
                                Change
                            </Button>
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="variant-search">Variant</Label>
                            <Input
                                id="variant-search"
                                placeholder="Search brand, model, size or SKU…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <div className="max-h-48 overflow-y-auto rounded-md border">
                                {candidates.length === 0 ? (
                                    <p className="text-muted-foreground p-2 text-sm">
                                        No matching, not-yet-stocked variants.
                                    </p>
                                ) : (
                                    candidates.map((v) => (
                                        <button
                                            type="button"
                                            key={v.id}
                                            className="hover:bg-accent block w-full px-3 py-2 text-left text-sm"
                                            onClick={() => {
                                                setSelected(v);
                                                form.setData(
                                                    'tyre_variant_id',
                                                    String(v.id),
                                                );
                                            }}
                                        >
                                            {variantLabel(v)}
                                        </button>
                                    ))
                                )}
                            </div>
                            <InputError message={form.errors.tyre_variant_id} />
                        </div>
                    )}

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="qty_on_hand">On hand</Label>
                            <Input
                                id="qty_on_hand"
                                type="number"
                                min={0}
                                value={form.data.qty_on_hand}
                                onChange={(e) =>
                                    form.setData('qty_on_hand', e.target.value)
                                }
                            />
                            <InputError message={form.errors.qty_on_hand} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="qty_reserved">Reserved</Label>
                            <Input
                                id="qty_reserved"
                                type="number"
                                min={0}
                                value={form.data.qty_reserved}
                                onChange={(e) =>
                                    form.setData('qty_reserved', e.target.value)
                                }
                            />
                            <InputError message={form.errors.qty_reserved} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="reorder_point">Reorder at</Label>
                            <Input
                                id="reorder_point"
                                type="number"
                                min={0}
                                value={form.data.reorder_point}
                                onChange={(e) =>
                                    form.setData(
                                        'reorder_point',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.reorder_point} />
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        disabled={form.processing || !selected}
                        onClick={submit}
                    >
                        Add stock row
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function EditInventoryItemDialog({ item }: { item: InventoryItem }) {
    const [open, setOpen] = useState(false);

    const form = useForm({
        tyre_variant_id: String(item.tyre_variant_id),
        qty_on_hand: String(item.qty_on_hand),
        qty_reserved: String(item.qty_reserved),
        reorder_point: String(item.reorder_point),
    });

    const submit = () => {
        form.put(InventoryItemController.update(item.id).url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Edit
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Edit stock levels — {item.tyre_variant?.sku}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid grid-cols-3 gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor={`qty_on_hand-${item.id}`}>
                            On hand
                        </Label>
                        <Input
                            id={`qty_on_hand-${item.id}`}
                            type="number"
                            min={0}
                            value={form.data.qty_on_hand}
                            onChange={(e) =>
                                form.setData('qty_on_hand', e.target.value)
                            }
                        />
                        <InputError message={form.errors.qty_on_hand} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`qty_reserved-${item.id}`}>
                            Reserved
                        </Label>
                        <Input
                            id={`qty_reserved-${item.id}`}
                            type="number"
                            min={0}
                            value={form.data.qty_reserved}
                            onChange={(e) =>
                                form.setData('qty_reserved', e.target.value)
                            }
                        />
                        <InputError message={form.errors.qty_reserved} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`reorder_point-${item.id}`}>
                            Reorder at
                        </Label>
                        <Input
                            id={`reorder_point-${item.id}`}
                            type="number"
                            min={0}
                            value={form.data.reorder_point}
                            onChange={(e) =>
                                form.setData('reorder_point', e.target.value)
                            }
                        />
                        <InputError message={form.errors.reorder_point} />
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        Save changes
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function InventoryLocationItemsIndex({
    stockLocation,
    inventoryItems,
    availableVariants,
}: {
    stockLocation: StockLocation;
    inventoryItems: InventoryItem[];
    availableVariants: AvailableVariant[];
}) {
    const stockedVariantIds = useMemo(
        () => new Set(inventoryItems.map((i) => i.tyre_variant_id)),
        [inventoryItems],
    );

    return (
        <>
            <Head title={`${stockLocation.name} — stock`} />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title={`${stockLocation.name} — stock`}
                        description={stockLocation.address}
                    />
                    <Can permission="inventory.manage">
                        <AddInventoryItemDialog
                            stockLocation={stockLocation}
                            availableVariants={availableVariants}
                            stockedVariantIds={stockedVariantIds}
                        />
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Stock rows</CardTitle>
                        <CardDescription>
                            <Link
                                href={StockLocationController.index().url}
                                className="hover:underline"
                            >
                                Back to all stock locations
                            </Link>
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {inventoryItems.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No stock rows yet at this location.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Variant
                                        </th>
                                        <th className="py-2 font-medium">
                                            On hand
                                        </th>
                                        <th className="py-2 font-medium">
                                            Reserved
                                        </th>
                                        <th className="py-2 font-medium">
                                            Available
                                        </th>
                                        <th className="py-2 font-medium">
                                            Reorder at
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {inventoryItems.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2">
                                                {item.tyre_variant ? (
                                                    <>
                                                        <span className="font-medium">
                                                            {
                                                                item
                                                                    .tyre_variant
                                                                    .tyre_model
                                                                    ?.brand
                                                                    ?.name
                                                            }{' '}
                                                            {
                                                                item
                                                                    .tyre_variant
                                                                    .tyre_model
                                                                    ?.name
                                                            }
                                                        </span>
                                                        <div className="text-muted-foreground text-xs">
                                                            {
                                                                item
                                                                    .tyre_variant
                                                                    .width
                                                            }
                                                            /
                                                            {
                                                                item
                                                                    .tyre_variant
                                                                    .profile
                                                            }
                                                            R
                                                            {
                                                                item
                                                                    .tyre_variant
                                                                    .rim_diameter
                                                            }{' '}
                                                            ·{' '}
                                                            {
                                                                item
                                                                    .tyre_variant
                                                                    .sku
                                                            }
                                                        </div>
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Variant #
                                                        {item.tyre_variant_id}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {item.qty_on_hand}
                                            </td>
                                            <td className="py-2">
                                                {item.qty_reserved}
                                            </td>
                                            <td className="py-2">
                                                {item.qty_on_hand -
                                                    item.qty_reserved}
                                            </td>
                                            <td className="py-2">
                                                {item.reorder_point}
                                            </td>
                                            <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                                <Can permission="inventory.manage">
                                                    <EditInventoryItemDialog
                                                        item={item}
                                                    />
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="text-destructive"
                                                        onClick={() =>
                                                            router.delete(
                                                                InventoryItemController.destroy(
                                                                    item.id,
                                                                ).url,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Remove
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
            </div>
        </>
    );
}

InventoryLocationItemsIndex.layout = {
    breadcrumbs: [
        { title: 'Inventory', href: StockLocationController.index().url },
    ] satisfies BreadcrumbItem[],
};
