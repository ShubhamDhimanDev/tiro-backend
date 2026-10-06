import { ListToolbar, useListFilter } from '@/components/list-toolbar';
import { useForm } from '@inertiajs/react';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import BrandController from '@/actions/App/Http/Controllers/Admin/Products/BrandController';
import TyreModelController from '@/actions/App/Http/Controllers/Admin/Products/TyreModelController';
import TyreVariantController from '@/actions/App/Http/Controllers/Admin/Products/TyreVariantController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { StringListEditor } from '@/components/string-list-editor';
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
    TYRE_CATEGORY_OPTIONS,
    TYRE_CONSTRUCTION_OPTIONS,
    TYRE_TYPE_OPTIONS,
    statusBadgeVariant,
} from '@/lib/enums';
import { slugify } from '@/lib/slug';
import type {
    Brand,
    Status,
    TyreCategory,
    TyreConstruction,
    TyreModel,
    TyreType,
} from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type TyreModelFormData = {
    name: string;
    slug: string;
    category: TyreCategory;
    tyre_type: TyreType;
    construction: TyreConstruction;
    run_flat: boolean;
    description: string;
    warranty_text: string;
    warranty_km: string;
    service_inclusions: string[];
    released_at: string;
    images: string[];
    status: Status;
};

function TyreModelFormDialog({
    brand,
    tyreModel,
}: {
    brand: Brand;
    tyreModel?: TyreModel;
}) {
    const isEdit = !!tyreModel;
    const [open, setOpen] = useState(false);
    const [slugTouched, setSlugTouched] = useState(isEdit);

    const form = useForm<TyreModelFormData>({
        name: tyreModel?.name ?? '',
        slug: tyreModel?.slug ?? '',
        category: tyreModel?.category ?? 'car',
        tyre_type: tyreModel?.tyre_type ?? 'highway',
        construction: tyreModel?.construction ?? 'radial',
        run_flat: tyreModel?.run_flat ?? false,
        description: tyreModel?.description ?? '',
        warranty_text: tyreModel?.warranty_text ?? '',
        warranty_km: tyreModel?.warranty_km?.toString() ?? '',
        service_inclusions: tyreModel?.service_inclusions ?? [],
        released_at: tyreModel?.released_at?.slice(0, 10) ?? '',
        images: tyreModel?.images ?? [],
        status: tyreModel?.status ?? 'active',
    });

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!isEdit) {
                    form.reset();
                    setSlugTouched(false);
                }
            },
        };

        if (isEdit) {
            form.put(TyreModelController.update(tyreModel.id).url, options);
        } else {
            form.post(TyreModelController.store(brand.id).url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New model'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit
                            ? `Edit ${tyreModel.name}`
                            : `New model under ${brand.name}`}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) => {
                                form.setData('name', e.target.value);
                                if (!slugTouched) {
                                    form.setData(
                                        'slug',
                                        slugify(
                                            `${brand.name} ${e.target.value}`,
                                        ),
                                    );
                                }
                            }}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="slug">Slug</Label>
                        <Input
                            id="slug"
                            value={form.data.slug}
                            onChange={(e) => {
                                setSlugTouched(true);
                                form.setData('slug', e.target.value);
                            }}
                        />
                        <InputError message={form.errors.slug} />
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="category">Category</Label>
                            <Select
                                value={form.data.category}
                                onValueChange={(v) =>
                                    form.setData('category', v as TyreCategory)
                                }
                            >
                                <SelectTrigger id="category" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {TYRE_CATEGORY_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.category} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="tyre_type">Tyre type</Label>
                            <Select
                                value={form.data.tyre_type}
                                onValueChange={(v) =>
                                    form.setData('tyre_type', v as TyreType)
                                }
                            >
                                <SelectTrigger
                                    id="tyre_type"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {TYRE_TYPE_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.tyre_type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="construction">Construction</Label>
                            <Select
                                value={form.data.construction}
                                onValueChange={(v) =>
                                    form.setData(
                                        'construction',
                                        v as TyreConstruction,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="construction"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {TYRE_CONSTRUCTION_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.construction} />
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="run_flat"
                            checked={form.data.run_flat}
                            onCheckedChange={(checked) =>
                                form.setData('run_flat', checked === true)
                            }
                        />
                        <Label htmlFor="run_flat" className="font-normal">
                            Run-flat
                        </Label>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">
                            Description / fitment notes
                        </Label>
                        <textarea
                            id="description"
                            className="border-input min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="warranty_text">Warranty text</Label>
                            <Input
                                id="warranty_text"
                                value={form.data.warranty_text}
                                onChange={(e) =>
                                    form.setData(
                                        'warranty_text',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.warranty_text} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="warranty_km">Warranty (km)</Label>
                            <Input
                                id="warranty_km"
                                type="number"
                                min={0}
                                value={form.data.warranty_km}
                                onChange={(e) =>
                                    form.setData('warranty_km', e.target.value)
                                }
                            />
                            <InputError message={form.errors.warranty_km} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="released_at">Released on</Label>
                        <Input
                            id="released_at"
                            type="date"
                            value={form.data.released_at}
                            onChange={(e) =>
                                form.setData('released_at', e.target.value)
                            }
                        />
                        <p className="text-muted-foreground text-xs">
                            Leave blank to sort by creation date in "latest
                            releases" browsing.
                        </p>
                        <InputError message={form.errors.released_at} />
                    </div>

                    <StringListEditor
                        label="Service inclusions"
                        values={form.data.service_inclusions}
                        onChange={(v) => form.setData('service_inclusions', v)}
                        placeholder="e.g. Fitting"
                        addLabel="Add inclusion"
                    />
                    <InputError message={form.errors.service_inclusions} />

                    <StringListEditor
                        label="Images"
                        values={form.data.images}
                        onChange={(v) => form.setData('images', v)}
                        placeholder="https://…"
                        addLabel="Add image URL"
                    />
                    <InputError message={form.errors.images} />

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
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create model'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function BrandModelsIndex({
    brand,
    tyreModels,
}: {
    brand: Brand;
    tyreModels: TyreModel[];
}) {
    const list = useListFilter(tyreModels, {
        placeholder: 'Search models by name or slug…',
        searchText: (i) => [i.name, i.slug],
        filters: {
            Status: { label: 'Status', get: (i) => i.status },
            Category: { label: 'Category', get: (i) => i.category },
            Type: { label: 'Type', get: (i) => i.tyre_type },
        },
    });

    return (
        <>
            <Head title={`${brand.name} — models`} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={`${brand.name} models`}
                        description="Each model's size variants are managed one level down."
                    />
                    <Can permission="products.manage">
                        <TyreModelFormDialog brand={brand} />
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Tyre models</CardTitle>
                        <CardDescription>
                            Select a model to manage its size variants.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ListToolbar {...list.toolbarProps} />
                        {tyreModels.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No models yet for this brand.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Name
                                        </th>
                                        <th className="py-2 font-medium">
                                            Category
                                        </th>
                                        <th className="py-2 font-medium">
                                            Type
                                        </th>
                                        <th className="py-2 font-medium">
                                            Variants
                                        </th>
                                        <th className="py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {list.filtered.map((tyreModel) => (
                                        <tr
                                            key={tyreModel.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2">
                                                <Link
                                                    href={
                                                        TyreVariantController.index(
                                                            tyreModel.id,
                                                        ).url
                                                    }
                                                    className="font-medium hover:underline"
                                                >
                                                    {tyreModel.name}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">
                                                    {tyreModel.slug}
                                                </div>
                                            </td>
                                            <td className="text-muted-foreground py-2 capitalize">
                                                {tyreModel.category}
                                            </td>
                                            <td className="text-muted-foreground py-2 capitalize">
                                                {tyreModel.tyre_type.replace(
                                                    '_',
                                                    ' ',
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {tyreModel.tyre_variants_count ??
                                                    0}
                                            </td>
                                            <td className="py-2">
                                                <Badge
                                                    variant={statusBadgeVariant(
                                                        tyreModel.status,
                                                    )}
                                                >
                                                    {tyreModel.status}
                                                </Badge>
                                            </td>
                                            <td className="py-2 text-right">
                                                <Can permission="products.manage">
                                                    <TyreModelFormDialog
                                                        brand={brand}
                                                        tyreModel={tyreModel}
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

BrandModelsIndex.layout = {
    breadcrumbs: [
        { title: 'Products', href: BrandController.index().url },
    ] satisfies BreadcrumbItem[],
};
