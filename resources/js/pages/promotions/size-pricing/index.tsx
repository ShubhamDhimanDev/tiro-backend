import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PromotionController from '@/actions/App/Http/Controllers/Admin/Promotions/PromotionController';
import SizePricingController from '@/actions/App/Http/Controllers/Admin/Promotions/SizePricingController';
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
    DialogDescription,
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
import {
    centsToDollarsInput,
    dollarsInputToCents,
    formatCents,
} from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import type { Status } from '@/types/catalog';
import type { SizePriceBand } from '@/types/promotions';

type BandFormData = {
    name: string;
    rim_min: string;
    rim_max: string;
    status: Status;
    /** Dollar strings, index 0 = 1 tyre. */
    prices: string[];
};

function tyreLabel(quantity: number): string {
    return quantity === 1 ? '1 tyre' : `${quantity} tyres`;
}

function BandFormDialog({
    band,
    maxTyres,
}: {
    band?: SizePriceBand;
    maxTyres: number;
}) {
    const isEdit = !!band;
    const [open, setOpen] = useState(false);

    const initialPrices = (): string[] =>
        Array.from({ length: maxTyres }, (_, index) =>
            centsToDollarsInput(
                band?.tiers.find((tier) => tier.quantity === index + 1)
                    ?.total_price,
            ),
        );

    const form = useForm<BandFormData>({
        name: band?.name ?? '',
        rim_min: band ? String(band.rim_min) : '',
        rim_max: band ? String(band.rim_max) : '',
        status: band?.status ?? 'active',
        prices: initialPrices(),
    });

    const setPrice = (index: number, value: string) => {
        form.setData(
            'prices',
            form.data.prices.map((price, i) => (i === index ? value : price)),
        );
    };

    const submit = () => {
        form.transform((data) => ({
            name: data.name,
            rim_min: Number.parseInt(data.rim_min, 10) || 0,
            rim_max: Number.parseInt(data.rim_max, 10) || 0,
            status: data.status,
            tiers: data.prices.map((price, index) => ({
                quantity: index + 1,
                total_price: dollarsInputToCents(price),
            })),
        }));

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
            form.put(SizePricingController.update(band.id).url, options);
        } else {
            form.post(SizePricingController.store().url, options);
        }
    };

    const tierErrors = Object.entries(form.errors)
        .filter(([key]) => key === 'tiers' || key.startsWith('tiers.'))
        .map(([, message]) => message);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New size band'}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit size band' : 'New size band'}
                    </DialogTitle>
                    <DialogDescription>
                        One price for every tyre in these rim sizes, whatever
                        the brand. Prices are the total for the order,
                        GST-inclusive and fitting included.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            placeholder="16–17 inch"
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="rim_min">Smallest rim (")</Label>
                            <Input
                                id="rim_min"
                                type="number"
                                min={10}
                                max={30}
                                value={form.data.rim_min}
                                onChange={(e) =>
                                    form.setData('rim_min', e.target.value)
                                }
                            />
                            <InputError message={form.errors.rim_min} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="rim_max">Largest rim (")</Label>
                            <Input
                                id="rim_max"
                                type="number"
                                min={10}
                                max={30}
                                value={form.data.rim_max}
                                onChange={(e) =>
                                    form.setData('rim_max', e.target.value)
                                }
                            />
                            <InputError message={form.errors.rim_max} />
                        </div>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            Total price ($)
                        </legend>
                        <div className="grid grid-cols-2 gap-4">
                            {form.data.prices.map((price, index) => (
                                <div key={index} className="grid gap-1.5">
                                    <Label htmlFor={`price-${index + 1}`}>
                                        {tyreLabel(index + 1)}
                                    </Label>
                                    <Input
                                        id={`price-${index + 1}`}
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={price}
                                        onChange={(e) =>
                                            setPrice(index, e.target.value)
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                        <p className="text-muted-foreground text-xs">
                            To make {maxTyres} tyres cost the same as{' '}
                            {maxTyres - 1}, enter the same figure in both.
                        </p>
                        {tierErrors.map((message) => (
                            <InputError key={message} message={message} />
                        ))}
                    </fieldset>

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
                        {isEdit ? 'Save changes' : 'Create band'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeleteBandButton({ band }: { band: SizePriceBand }) {
    const destroy = () => {
        if (
            !confirm(
                `Delete the "${band.name}" band? Tyres in these sizes go back to their own listed price.`,
            )
        ) {
            return;
        }

        router.delete(SizePricingController.destroy(band.id).url, {
            preserveScroll: true,
        });
    };

    return (
        <Button variant="outline" size="sm" onClick={destroy}>
            Delete
        </Button>
    );
}

export default function SizePricingIndex({
    bands,
    unpricedRims,
    maxTyres,
}: {
    bands: SizePriceBand[];
    unpricedRims: number[];
    maxTyres: number;
}) {
    const quantities = Array.from({ length: maxTyres }, (_, i) => i + 1);

    return (
        <>
            <Head title="Size pricing" />

            <div className="flex justify-end">
                <Can permission="promotions.manage">
                    <BandFormDialog maxTyres={maxTyres} />
                </Can>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Size pricing</CardTitle>
                    <CardDescription>
                        Brand-agnostic prices set by rim size alone. The price
                        is the total for the order (GST-inclusive, fitting
                        included) and steps with the number of tyres; a mixed
                        order is priced per band. An order holds at most{' '}
                        {maxTyres} tyres. Saving a band updates the listed
                        price of every tyre in its sizes.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {unpricedRims.length > 0 && (
                        <p
                            role="status"
                            className="bg-muted rounded-md px-3 py-2 text-sm"
                        >
                            No active band covers{' '}
                            {unpricedRims.map((rim) => `${rim}"`).join(', ')}.
                            Tyres in those sizes keep their own listed price and
                            get no quantity pricing.
                        </p>
                    )}

                    {bands.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No size bands yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">Band</th>
                                    <th className="py-2 font-medium">Rims</th>
                                    {quantities.map((quantity) => (
                                        <th
                                            key={quantity}
                                            className="py-2 text-right font-medium"
                                        >
                                            {tyreLabel(quantity)}
                                        </th>
                                    ))}
                                    <th className="py-2 pl-4 font-medium">
                                        Status
                                    </th>
                                    <th className="py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {bands.map((band) => (
                                    <tr
                                        key={band.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 font-medium">
                                            {band.name}
                                        </td>
                                        <td className="py-2">
                                            {band.rim_min === band.rim_max
                                                ? `${band.rim_min}"`
                                                : `${band.rim_min}"–${band.rim_max}"`}
                                        </td>
                                        {quantities.map((quantity) => {
                                            const tier = band.tiers.find(
                                                (t) => t.quantity === quantity,
                                            );

                                            return (
                                                <td
                                                    key={quantity}
                                                    className="py-2 text-right tabular-nums"
                                                >
                                                    {tier
                                                        ? formatCents(
                                                              tier.total_price,
                                                          )
                                                        : '—'}
                                                </td>
                                            );
                                        })}
                                        <td className="py-2 pl-4">
                                            <Badge
                                                variant={statusBadgeVariant(
                                                    band.status,
                                                )}
                                            >
                                                {band.status}
                                            </Badge>
                                        </td>
                                        <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                            <Can permission="promotions.manage">
                                                <BandFormDialog
                                                    band={band}
                                                    maxTyres={maxTyres}
                                                />
                                                <DeleteBandButton band={band} />
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

SizePricingIndex.layout = {
    breadcrumbs: [
        { title: 'Promotions', href: PromotionController.index().url },
        { title: 'Size pricing', href: SizePricingController.index().url },
    ] satisfies BreadcrumbItem[],
};
