import { useForm } from '@inertiajs/react';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import BrandController from '@/actions/App/Http/Controllers/Admin/Products/BrandController';
import TyreModelController from '@/actions/App/Http/Controllers/Admin/Products/TyreModelController';
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
import { STATUS_OPTIONS, statusBadgeVariant } from '@/lib/enums';
import { slugify } from '@/lib/slug';
import type { Brand, Status } from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type BrandFormData = {
    name: string;
    slug: string;
    logo_path: string;
    country_of_origin: string;
    status: Status;
};

function BrandFormDialog({ brand }: { brand?: Brand }) {
    const isEdit = !!brand;
    const [open, setOpen] = useState(false);
    const [slugTouched, setSlugTouched] = useState(isEdit);

    const form = useForm<BrandFormData>({
        name: brand?.name ?? '',
        slug: brand?.slug ?? '',
        logo_path: brand?.logo_path ?? '',
        country_of_origin: brand?.country_of_origin ?? '',
        status: brand?.status ?? 'active',
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
            form.put(BrandController.update(brand.id).url, options);
        } else {
            form.post(BrandController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New brand'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? `Edit ${brand.name}` : 'New brand'}
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
                                        slugify(e.target.value),
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

                    <div className="grid gap-2">
                        <Label htmlFor="country_of_origin">
                            Country of origin
                        </Label>
                        <Input
                            id="country_of_origin"
                            value={form.data.country_of_origin}
                            onChange={(e) =>
                                form.setData(
                                    'country_of_origin',
                                    e.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.country_of_origin} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="logo_path">Logo URL / path</Label>
                        <Input
                            id="logo_path"
                            placeholder="https://…"
                            value={form.data.logo_path}
                            onChange={(e) =>
                                form.setData('logo_path', e.target.value)
                            }
                        />
                        <InputError message={form.errors.logo_path} />
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
                        {isEdit ? 'Save changes' : 'Create brand'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function BrandsIndex({ brands }: { brands: Brand[] }) {
    return (
        <>
            <Head title="Brands" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Brands"
                        description="Every tyre brand sold, and the drill-down into each brand's models and size variants."
                    />
                    <Can permission="products.manage">
                        <BrandFormDialog />
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>All brands</CardTitle>
                        <CardDescription>
                            Select a brand to manage its tyre models.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {brands.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No brands yet.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Name
                                        </th>
                                        <th className="py-2 font-medium">
                                            Country
                                        </th>
                                        <th className="py-2 font-medium">
                                            Models
                                        </th>
                                        <th className="py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {brands.map((brand) => (
                                        <tr
                                            key={brand.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2">
                                                <Link
                                                    href={
                                                        TyreModelController.index(
                                                            brand.id,
                                                        ).url
                                                    }
                                                    className="font-medium hover:underline"
                                                >
                                                    {brand.name}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">
                                                    {brand.slug}
                                                </div>
                                            </td>
                                            <td className="text-muted-foreground py-2">
                                                {brand.country_of_origin ?? '—'}
                                            </td>
                                            <td className="py-2">
                                                {brand.tyre_models_count ?? 0}
                                            </td>
                                            <td className="py-2">
                                                <Badge
                                                    variant={statusBadgeVariant(
                                                        brand.status,
                                                    )}
                                                >
                                                    {brand.status}
                                                </Badge>
                                            </td>
                                            <td className="py-2 text-right">
                                                <Can permission="products.manage">
                                                    <BrandFormDialog
                                                        brand={brand}
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

BrandsIndex.layout = {
    breadcrumbs: [
        { title: 'Products', href: BrandController.index().url },
    ] satisfies BreadcrumbItem[],
};
