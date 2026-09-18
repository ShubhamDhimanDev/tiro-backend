import { router, useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import VehicleController from '@/actions/App/Http/Controllers/Admin/Vehicles/VehicleController';
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
import {
    STATUS_OPTIONS,
    VEHICLE_FITMENT_CONFIDENCE_OPTIONS,
    statusBadgeVariant,
} from '@/lib/enums';
import type {
    Vehicle,
    VehicleFitment,
    VehicleFitmentConfidence,
    VehicleFitmentPosition,
} from '@/types/vehicles';
import type { Status } from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type FitmentRowFormData = {
    width: string;
    profile: string;
    rim_diameter: string;
    load_index: string;
    speed_rating: string;
    confidence: VehicleFitmentConfidence;
    notes: string;
};

type VehicleFormData = {
    make: string;
    model: string;
    series: string;
    body_type: string;
    year_from: string;
    year_to: string;
    slug: string;
    status: Status;
    is_staggered: boolean;
    all: FitmentRowFormData;
    front: FitmentRowFormData;
    rear: FitmentRowFormData;
};

function emptyFitmentRow(): FitmentRowFormData {
    return {
        width: '',
        profile: '',
        rim_diameter: '',
        load_index: '',
        speed_rating: '',
        confidence: 'confirmed',
        notes: '',
    };
}

function fitmentRowFrom(
    vehicle: Vehicle | undefined,
    position: VehicleFitmentPosition,
): FitmentRowFormData {
    const row = vehicle?.fitments.find((f) => f.position === position);

    if (!row) {
        return emptyFitmentRow();
    }

    return {
        width: String(row.width),
        profile: String(row.profile),
        rim_diameter: String(row.rim_diameter),
        load_index: row.load_index ?? '',
        speed_rating: row.speed_rating ?? '',
        confidence: row.confidence,
        notes: row.notes ?? '',
    };
}

/** Renders "225/45R18" from a fitment row, or "—" if the row is incomplete. */
function sizeLabel(row: FitmentRowFormData | VehicleFitment): string {
    if (!row.width || !row.profile || !row.rim_diameter) {
        return '—';
    }

    return `${row.width}/${row.profile}R${row.rim_diameter}`;
}

function FitmentSummary({ fitments }: { fitments: VehicleFitment[] }) {
    const all = fitments.find((f) => f.position === 'all');
    const front = fitments.find((f) => f.position === 'front');
    const rear = fitments.find((f) => f.position === 'rear');

    if (fitments.length === 0) {
        return <span className="text-muted-foreground">No fitment set</span>;
    }

    if (all) {
        return <span>{sizeLabel(all)}</span>;
    }

    return (
        <span>
            F {front ? sizeLabel(front) : '—'} · R{' '}
            {rear ? sizeLabel(rear) : '—'}
        </span>
    );
}

function FitmentRowFields({
    idPrefix,
    errorPrefix,
    label,
    value,
    onChange,
    errors,
}: {
    idPrefix: string;
    errorPrefix: string;
    label: string;
    value: FitmentRowFormData;
    onChange: (next: FitmentRowFormData) => void;
    errors: Record<string, string | undefined>;
}) {
    const set = <K extends keyof FitmentRowFormData>(
        key: K,
        v: FitmentRowFormData[K],
    ) => onChange({ ...value, [key]: v });

    return (
        <div className="space-y-3 rounded-md border p-3">
            <p className="text-sm font-medium">{label}</p>

            <div className="grid grid-cols-3 gap-3">
                <div className="grid gap-2">
                    <Label htmlFor={`${idPrefix}-width`}>Width</Label>
                    <Input
                        id={`${idPrefix}-width`}
                        type="number"
                        value={value.width}
                        onChange={(e) => set('width', e.target.value)}
                    />
                    <InputError message={errors[`${errorPrefix}.width`]} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`${idPrefix}-profile`}>Profile</Label>
                    <Input
                        id={`${idPrefix}-profile`}
                        type="number"
                        value={value.profile}
                        onChange={(e) => set('profile', e.target.value)}
                    />
                    <InputError message={errors[`${errorPrefix}.profile`]} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`${idPrefix}-rim`}>Rim (in)</Label>
                    <Input
                        id={`${idPrefix}-rim`}
                        type="number"
                        value={value.rim_diameter}
                        onChange={(e) => set('rim_diameter', e.target.value)}
                    />
                    <InputError
                        message={errors[`${errorPrefix}.rim_diameter`]}
                    />
                </div>
            </div>

            <div className="grid grid-cols-3 gap-3">
                <div className="grid gap-2">
                    <Label htmlFor={`${idPrefix}-load`}>Load index</Label>
                    <Input
                        id={`${idPrefix}-load`}
                        value={value.load_index}
                        onChange={(e) => set('load_index', e.target.value)}
                    />
                    <InputError message={errors[`${errorPrefix}.load_index`]} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`${idPrefix}-speed`}>Speed rating</Label>
                    <Input
                        id={`${idPrefix}-speed`}
                        value={value.speed_rating}
                        onChange={(e) => set('speed_rating', e.target.value)}
                    />
                    <InputError
                        message={errors[`${errorPrefix}.speed_rating`]}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`${idPrefix}-confidence`}>Confidence</Label>
                    <Select
                        value={value.confidence}
                        onValueChange={(v) =>
                            set('confidence', v as VehicleFitmentConfidence)
                        }
                    >
                        <SelectTrigger
                            id={`${idPrefix}-confidence`}
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {VEHICLE_FITMENT_CONFIDENCE_OPTIONS.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors[`${errorPrefix}.confidence`]} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-notes`}>Notes</Label>
                <textarea
                    id={`${idPrefix}-notes`}
                    className="border-input min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                    value={value.notes}
                    onChange={(e) => set('notes', e.target.value)}
                />
                <InputError message={errors[`${errorPrefix}.notes`]} />
            </div>
        </div>
    );
}

function VehicleFormDialog({ vehicle }: { vehicle?: Vehicle }) {
    const isEdit = !!vehicle;
    const [open, setOpen] = useState(false);
    const existingIsStaggered =
        vehicle?.fitments.some((f) => f.is_staggered) ?? false;
    const hasVendorFeedRow =
        vehicle?.fitments.some((f) => f.source === 'vendor_feed') ?? false;

    const form = useForm<VehicleFormData>({
        make: vehicle?.make ?? '',
        model: vehicle?.model ?? '',
        series: vehicle?.series ?? '',
        body_type: vehicle?.body_type ?? '',
        year_from: vehicle?.year_from?.toString() ?? '',
        year_to: vehicle?.year_to?.toString() ?? '',
        slug: vehicle?.slug ?? '',
        status: vehicle?.status ?? 'active',
        is_staggered: existingIsStaggered,
        all: fitmentRowFrom(vehicle, 'all'),
        front: fitmentRowFrom(vehicle, 'front'),
        rear: fitmentRowFrom(vehicle, 'rear'),
    });

    const errors = form.errors as Record<string, string | undefined>;

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

        form.transform(({ all, front, rear, is_staggered, slug, ...rest }) => ({
            ...rest,
            is_staggered,
            slug: !isEdit && slug.trim() === '' ? null : slug,
            fitments: is_staggered
                ? [
                      { position: 'front', ...front },
                      { position: 'rear', ...rear },
                  ]
                : [{ position: 'all', ...all }],
        }));

        if (isEdit) {
            form.put(VehicleController.update(vehicle.id).url, options);
        } else {
            form.post(VehicleController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New vehicle'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit
                            ? `Edit ${vehicle.make} ${vehicle.model}`
                            : 'New vehicle'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="make">Make</Label>
                            <Input
                                id="make"
                                value={form.data.make}
                                onChange={(e) =>
                                    form.setData('make', e.target.value)
                                }
                            />
                            <InputError message={form.errors.make} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="model">Model</Label>
                            <Input
                                id="model"
                                value={form.data.model}
                                onChange={(e) =>
                                    form.setData('model', e.target.value)
                                }
                            />
                            <InputError message={form.errors.model} />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="series">Series / generation</Label>
                            <Input
                                id="series"
                                value={form.data.series}
                                onChange={(e) =>
                                    form.setData('series', e.target.value)
                                }
                            />
                            <InputError message={form.errors.series} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="body_type">Body type</Label>
                            <Input
                                id="body_type"
                                value={form.data.body_type}
                                onChange={(e) =>
                                    form.setData('body_type', e.target.value)
                                }
                            />
                            <InputError message={form.errors.body_type} />
                        </div>
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="year_from">Year from</Label>
                            <Input
                                id="year_from"
                                type="number"
                                value={form.data.year_from}
                                onChange={(e) =>
                                    form.setData('year_from', e.target.value)
                                }
                            />
                            <InputError message={form.errors.year_from} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="year_to">Year to</Label>
                            <Input
                                id="year_to"
                                type="number"
                                value={form.data.year_to}
                                onChange={(e) =>
                                    form.setData('year_to', e.target.value)
                                }
                            />
                            <InputError message={form.errors.year_to} />
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

                    <div className="grid gap-2">
                        <Label htmlFor="slug">Slug</Label>
                        <Input
                            id="slug"
                            placeholder={
                                isEdit
                                    ? undefined
                                    : 'Leave blank to auto-generate'
                            }
                            value={form.data.slug}
                            onChange={(e) =>
                                form.setData('slug', e.target.value)
                            }
                        />
                        <InputError message={form.errors.slug} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="is_staggered"
                            checked={form.data.is_staggered}
                            onCheckedChange={(checked) =>
                                form.setData('is_staggered', checked === true)
                            }
                        />
                        <Label htmlFor="is_staggered" className="font-normal">
                            Staggered fitment (different front/rear sizes)
                        </Label>
                    </div>
                    <InputError message={errors.fitments} />

                    {hasVendorFeedRow && (
                        <p className="text-muted-foreground text-xs">
                            One or more of this vehicle's fitment rows came from
                            an automated import. Saving here marks all of its
                            rows as manually confirmed (source becomes
                            "manual").
                        </p>
                    )}

                    {form.data.is_staggered ? (
                        <div className="grid grid-cols-2 gap-4">
                            <FitmentRowFields
                                idPrefix="front"
                                errorPrefix="fitments.0"
                                label="Front"
                                value={form.data.front}
                                onChange={(v) => form.setData('front', v)}
                                errors={errors}
                            />
                            <FitmentRowFields
                                idPrefix="rear"
                                errorPrefix="fitments.1"
                                label="Rear"
                                value={form.data.rear}
                                onChange={(v) => form.setData('rear', v)}
                                errors={errors}
                            />
                        </div>
                    ) : (
                        <FitmentRowFields
                            idPrefix="all"
                            errorPrefix="fitments.0"
                            label="OE size (all positions)"
                            value={form.data.all}
                            onChange={(v) => form.setData('all', v)}
                            errors={errors}
                        />
                    )}
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create vehicle'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeleteVehicleDialog({ vehicle }: { vehicle: Vehicle }) {
    const [open, setOpen] = useState(false);

    const confirmDelete = () => {
        router.delete(VehicleController.destroy(vehicle.id).url, {
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
                    <DialogTitle>
                        Delete {vehicle.make} {vehicle.model}?
                    </DialogTitle>
                </DialogHeader>
                <p className="text-muted-foreground text-sm">
                    This permanently removes the vehicle and its{' '}
                    {vehicle.fitments.length} fitment row(s). This cannot be
                    undone.
                </p>
                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={confirmDelete}>
                        Delete vehicle
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function VehiclesIndex({ vehicles }: { vehicles: Vehicle[] }) {
    return (
        <>
            <Head title="Vehicles" />

            <div className="flex justify-end">
                <Can permission="vehicles.manage">
                    <VehicleFormDialog />
                </Can>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Vehicles</CardTitle>
                    <CardDescription>
                        Make/model/generation entries the manual vehicle picker
                        resolves to, each with its OE fitment size(s).
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {vehicles.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No vehicles yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">
                                        Vehicle
                                    </th>
                                    <th className="py-2 font-medium">Years</th>
                                    <th className="py-2 font-medium">
                                        OE fitment
                                    </th>
                                    <th className="py-2 font-medium">Status</th>
                                    <th className="py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {vehicles.map((vehicle) => (
                                    <tr
                                        key={vehicle.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 font-medium">
                                            {vehicle.make} {vehicle.model}
                                            <div className="text-muted-foreground text-xs font-normal">
                                                {[
                                                    vehicle.series,
                                                    vehicle.body_type,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' — ')}
                                            </div>
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {vehicle.year_from}–
                                            {vehicle.year_to}
                                        </td>
                                        <td className="py-2">
                                            <FitmentSummary
                                                fitments={vehicle.fitments}
                                            />
                                        </td>
                                        <td className="py-2">
                                            <Badge
                                                variant={statusBadgeVariant(
                                                    vehicle.status,
                                                )}
                                            >
                                                {vehicle.status}
                                            </Badge>
                                        </td>
                                        <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                            <Can permission="vehicles.manage">
                                                <VehicleFormDialog
                                                    vehicle={vehicle}
                                                />
                                                <DeleteVehicleDialog
                                                    vehicle={vehicle}
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

VehiclesIndex.layout = {
    breadcrumbs: [
        { title: 'Vehicles', href: VehicleController.index().url },
    ] satisfies BreadcrumbItem[],
};
