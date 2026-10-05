import { useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import BrandController from '@/actions/App/Http/Controllers/Admin/Products/BrandController';
import TyreVariantController from '@/actions/App/Http/Controllers/Admin/Products/TyreVariantController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
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
import {
    STATUS_OPTIONS,
    TYRE_SIDEWALL_OPTIONS,
    statusBadgeVariant,
} from '@/lib/enums';
import { centsToDollarsInput, dollarsInputToCents } from '@/lib/money';
import type {
    Status,
    TyreModel,
    TyreSidewall,
    TyreVariant,
} from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type TyreVariantFormData = {
    sku: string;
    slug: string;
    width: string;
    profile: string;
    rim_diameter: string;
    load_index: string;
    speed_rating: string;
    sidewall: TyreSidewall;
    ean: string;
    weight_kg: string;
    price: string;
    status: Status;
};

function TyreVariantFormDialog({
    tyreModel,
    tyreVariant,
}: {
    tyreModel: TyreModel;
    tyreVariant?: TyreVariant;
}) {
    const isEdit = !!tyreVariant;
    const [open, setOpen] = useState(false);

    const form = useForm<TyreVariantFormData>({
        sku: tyreVariant?.sku ?? '',
        slug: tyreVariant?.slug ?? '',
        width: tyreVariant?.width?.toString() ?? '',
        profile: tyreVariant?.profile?.toString() ?? '',
        rim_diameter: tyreVariant?.rim_diameter?.toString() ?? '',
        load_index: tyreVariant?.load_index ?? '',
        speed_rating: tyreVariant?.speed_rating ?? '',
        sidewall: tyreVariant?.sidewall ?? 'standard',
        ean: tyreVariant?.ean ?? '',
        weight_kg: tyreVariant?.weight_kg ?? '',
        price: centsToDollarsInput(tyreVariant?.base_price),
        status: tyreVariant?.status ?? 'active',
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

        form.transform((data) => ({
            ...data,
            // Blank slug on create -> let the model auto-generate one;
            // required (and left as-is) on edit.
            slug: !isEdit && data.slug.trim() === '' ? null : data.slug,
            base_price: dollarsInputToCents(data.price),
        }));

        if (isEdit) {
            form.put(TyreVariantController.update(tyreVariant.id).url, options);
        } else {
            form.post(TyreVariantController.store(tyreModel.id).url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New variant'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit
                            ? `Edit ${tyreVariant.sku}`
                            : `New variant under ${tyreModel.name}`}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="sku">SKU</Label>
                            <Input
                                id="sku"
                                value={form.data.sku}
                                onChange={(e) =>
                                    form.setData('sku', e.target.value)
                                }
                            />
                            <InputError message={form.errors.sku} />
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
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="width">Width</Label>
                            <Input
                                id="width"
                                type="number"
                                value={form.data.width}
                                onChange={(e) =>
                                    form.setData('width', e.target.value)
                                }
                            />
                            <InputError message={form.errors.width} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="profile">Profile</Label>
                            <Input
                                id="profile"
                                type="number"
                                value={form.data.profile}
                                onChange={(e) =>
                                    form.setData('profile', e.target.value)
                                }
                            />
                            <InputError message={form.errors.profile} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="rim_diameter">Rim (in)</Label>
                            <Input
                                id="rim_diameter"
                                type="number"
                                value={form.data.rim_diameter}
                                onChange={(e) =>
                                    form.setData('rim_diameter', e.target.value)
                                }
                            />
                            <InputError message={form.errors.rim_diameter} />
                        </div>
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="load_index">Load index</Label>
                            <Input
                                id="load_index"
                                value={form.data.load_index}
                                onChange={(e) =>
                                    form.setData('load_index', e.target.value)
                                }
                            />
                            <InputError message={form.errors.load_index} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="speed_rating">Speed rating</Label>
                            <Input
                                id="speed_rating"
                                value={form.data.speed_rating}
                                onChange={(e) =>
                                    form.setData('speed_rating', e.target.value)
                                }
                            />
                            <InputError message={form.errors.speed_rating} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sidewall">Sidewall</Label>
                            <Select
                                value={form.data.sidewall}
                                onValueChange={(v) =>
                                    form.setData('sidewall', v as TyreSidewall)
                                }
                            >
                                <SelectTrigger id="sidewall" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {TYRE_SIDEWALL_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.sidewall} />
                        </div>
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="ean">EAN</Label>
                            <Input
                                id="ean"
                                value={form.data.ean}
                                onChange={(e) =>
                                    form.setData('ean', e.target.value)
                                }
                            />
                            <InputError message={form.errors.ean} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="weight_kg">Weight (kg)</Label>
                            <Input
                                id="weight_kg"
                                type="number"
                                step="0.01"
                                value={form.data.weight_kg}
                                onChange={(e) =>
                                    form.setData('weight_kg', e.target.value)
                                }
                            />
                            <InputError message={form.errors.weight_kg} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="price">Price (AUD)</Label>
                            <Input
                                id="price"
                                type="number"
                                step="0.01"
                                min={0}
                                value={form.data.price}
                                onChange={(e) =>
                                    form.setData('price', e.target.value)
                                }
                            />
                            <InputError
                                message={
                                    (
                                        form.errors as Record<
                                            string,
                                            string | undefined
                                        >
                                    ).base_price
                                }
                            />
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
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create variant'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function TyreModelVariantsIndex({
    tyreModel,
    tyreVariants,
}: {
    tyreModel: TyreModel;
    tyreVariants: TyreVariant[];
}) {
    return (
        <>
            <Head title={`${tyreModel.name} — variants`} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={`${tyreModel.brand?.name ?? ''} ${tyreModel.name} — size variants`}
                        description="Every sellable SKU (size) under this model."
                    />
                    <Can permission="products.manage">
                        <TyreVariantFormDialog tyreModel={tyreModel} />
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Variants</CardTitle>
                        <CardDescription>
                            {tyreVariants.length} size
                            {tyreVariants.length === 1 ? '' : 's'} listed.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {tyreVariants.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No variants yet for this model.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Size
                                        </th>
                                        <th className="py-2 font-medium">
                                            SKU
                                        </th>
                                        <th className="py-2 font-medium">
                                            Load/Speed
                                        </th>
                                        <th className="py-2 font-medium">
                                            Price
                                        </th>
                                        <th className="py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {tyreVariants.map((variant) => (
                                        <tr
                                            key={variant.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2 font-medium">
                                                {variant.width}/
                                                {variant.profile}R
                                                {variant.rim_diameter}
                                                <div className="text-muted-foreground text-xs font-normal">
                                                    {variant.slug}
                                                </div>
                                            </td>
                                            <td className="text-muted-foreground py-2">
                                                {variant.sku}
                                            </td>
                                            <td className="text-muted-foreground py-2">
                                                {variant.load_index}
                                                {variant.speed_rating}
                                            </td>
                                            <td className="py-2">
                                                $
                                                {(
                                                    variant.base_price / 100
                                                ).toFixed(2)}
                                            </td>
                                            <td className="py-2">
                                                <Badge
                                                    variant={statusBadgeVariant(
                                                        variant.status,
                                                    )}
                                                >
                                                    {variant.status}
                                                </Badge>
                                            </td>
                                            <td className="py-2 text-right">
                                                <Can permission="products.manage">
                                                    <TyreVariantFormDialog
                                                        tyreModel={tyreModel}
                                                        tyreVariant={variant}
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

TyreModelVariantsIndex.layout = {
    breadcrumbs: [
        { title: 'Products', href: BrandController.index().url },
    ] satisfies BreadcrumbItem[],
};
