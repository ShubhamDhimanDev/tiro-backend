import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PromotionController from '@/actions/App/Http/Controllers/Admin/Promotions/PromotionController';
import PromotionEligibilityController from '@/actions/App/Http/Controllers/Admin/Promotions/PromotionEligibilityController';
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
import { Checkbox } from '@/components/ui/checkbox';
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
    PROMOTION_ELIGIBILITY_SCOPE_OPTIONS,
    PROMOTION_TYPE_OPTIONS,
    STATUS_OPTIONS,
    TYRE_CATEGORY_OPTIONS,
    statusBadgeVariant,
} from '@/lib/enums';
import {
    centsToDollarsInput,
    dollarsInputToCents,
    formatCents,
} from '@/lib/money';
import type { Status } from '@/types/catalog';
import type {
    Promotion,
    PromotionBrandOption,
    PromotionEligibility,
    PromotionEligibilityScope,
    PromotionTyreModelOption,
    PromotionTyreVariantOption,
    PromotionType,
} from '@/types/promotions';
import type { BreadcrumbItem } from '@/types';

type PromotionFormData = {
    name: string;
    type: PromotionType;
    /** Percent (0-100) for `type=percentage`; dollars-input string otherwise, converted to cents on submit. */
    valueInput: string;
    starts_at: string;
    ends_at: string;
    usage_limit: string;
    stock_limit: string;
    stackable: boolean;
    status: Status;
};

type CatalogueLookups = {
    brands: PromotionBrandOption[];
    tyreModels: PromotionTyreModelOption[];
    tyreVariants: PromotionTyreVariantOption[];
    serviceZones: { id: number; name: string }[];
};

/** Human label for an eligibility row's `scope_id`, resolved against the lookups the index page loaded. */
function eligibilityLabel(
    eligibility: PromotionEligibility,
    { brands, tyreModels, tyreVariants }: CatalogueLookups,
): string {
    switch (eligibility.scope) {
        case 'brand': {
            const brand = brands.find(
                (b) => String(b.id) === eligibility.scope_id,
            );
            return brand?.name ?? `Brand #${eligibility.scope_id}`;
        }
        case 'tyre_model': {
            const model = tyreModels.find(
                (m) => String(m.id) === eligibility.scope_id,
            );
            return model
                ? `${model.brand?.name ?? ''} ${model.name}`.trim()
                : `Model #${eligibility.scope_id}`;
        }
        case 'tyre_variant': {
            const variant = tyreVariants.find(
                (v) => String(v.id) === eligibility.scope_id,
            );
            if (!variant) {
                return `Variant #${eligibility.scope_id}`;
            }
            const model = variant.tyre_model;
            return `${model?.brand?.name ?? ''} ${model?.name ?? ''} — ${variant.sku} (${variant.width}/${variant.profile} R${variant.rim_diameter})`.trim();
        }
        case 'category': {
            const category = TYRE_CATEGORY_OPTIONS.find(
                (o) => o.value === eligibility.scope_id,
            );
            return category?.label ?? eligibility.scope_id;
        }
    }
}

function AddEligibilityForm({
    promotion,
    lookups,
}: {
    promotion: Promotion;
    lookups: CatalogueLookups;
}) {
    const { brands, tyreModels, tyreVariants, serviceZones } = lookups;

    const form = useForm<{
        scope: PromotionEligibilityScope;
        scope_id: string;
        service_zone_id: string;
    }>({
        scope: 'brand',
        scope_id: '',
        service_zone_id: '',
    });

    const submit = () => {
        const payload = {
            scope: form.data.scope,
            scope_id: form.data.scope_id,
            service_zone_id: form.data.service_zone_id || null,
        };

        form.transform(() => payload);

        form.post(PromotionEligibilityController.store(promotion.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('scope_id', '');
            },
        });
    };

    return (
        <div className="grid gap-2 rounded-md border border-dashed p-3">
            <p className="text-sm font-medium">Add eligibility rule</p>
            <div className="grid grid-cols-2 gap-3">
                <div className="grid gap-1.5">
                    <Label htmlFor="elig-scope">Scope</Label>
                    <Select
                        value={form.data.scope}
                        onValueChange={(v) => {
                            form.setData(
                                'scope',
                                v as PromotionEligibilityScope,
                            );
                            form.setData('scope_id', '');
                        }}
                    >
                        <SelectTrigger id="elig-scope" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PROMOTION_ELIGIBILITY_SCOPE_OPTIONS.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="elig-zone">
                        Zone restriction (optional)
                    </Label>
                    <Select
                        value={form.data.service_zone_id || 'none'}
                        onValueChange={(v) =>
                            form.setData(
                                'service_zone_id',
                                v === 'none' ? '' : v,
                            )
                        }
                    >
                        <SelectTrigger id="elig-zone" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">All zones</SelectItem>
                            {serviceZones.map((zone) => (
                                <SelectItem
                                    key={zone.id}
                                    value={String(zone.id)}
                                >
                                    {zone.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.service_zone_id} />
                </div>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="elig-scope-id">
                    {form.data.scope === 'brand' && 'Brand'}
                    {form.data.scope === 'tyre_model' && 'Tyre model'}
                    {form.data.scope === 'tyre_variant' && 'Tyre variant'}
                    {form.data.scope === 'category' && 'Category'}
                </Label>
                <Select
                    value={form.data.scope_id}
                    onValueChange={(v) => form.setData('scope_id', v)}
                >
                    <SelectTrigger id="elig-scope-id" className="w-full">
                        <SelectValue placeholder="Select…" />
                    </SelectTrigger>
                    <SelectContent>
                        {form.data.scope === 'brand' &&
                            brands.map((brand) => (
                                <SelectItem
                                    key={brand.id}
                                    value={String(brand.id)}
                                >
                                    {brand.name}
                                </SelectItem>
                            ))}
                        {form.data.scope === 'tyre_model' &&
                            tyreModels.map((model) => (
                                <SelectItem
                                    key={model.id}
                                    value={String(model.id)}
                                >
                                    {model.brand?.name} {model.name}
                                </SelectItem>
                            ))}
                        {form.data.scope === 'tyre_variant' &&
                            tyreVariants.map((variant) => (
                                <SelectItem
                                    key={variant.id}
                                    value={String(variant.id)}
                                >
                                    {variant.tyre_model?.brand?.name}{' '}
                                    {variant.tyre_model?.name} — {variant.sku} (
                                    {variant.width}/{variant.profile} R
                                    {variant.rim_diameter})
                                </SelectItem>
                            ))}
                        {form.data.scope === 'category' &&
                            TYRE_CATEGORY_OPTIONS.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                    </SelectContent>
                </Select>
                <InputError message={form.errors.scope_id} />
            </div>

            <div className="flex justify-end">
                <Button
                    size="sm"
                    disabled={form.processing || !form.data.scope_id}
                    onClick={submit}
                >
                    Add rule
                </Button>
            </div>
        </div>
    );
}

function EligibilityEditor({
    promotion,
    lookups,
}: {
    promotion: Promotion;
    lookups: CatalogueLookups;
}) {
    const eligibilities = promotion.eligibilities ?? [];

    const remove = (eligibilityId: number) => {
        router.delete(
            PromotionEligibilityController.destroy([
                promotion.id,
                eligibilityId,
            ]).url,
            { preserveScroll: true },
        );
    };

    return (
        <div className="grid gap-2">
            <Label>Eligibility rules</Label>
            <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border p-2">
                {eligibilities.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No eligibility rules yet — this promotion won't match
                        any cart contents until at least one rule is added.
                    </p>
                ) : (
                    eligibilities.map((eligibility) => (
                        <div
                            key={eligibility.id}
                            className="flex items-center justify-between gap-2 rounded px-2 py-1 text-sm"
                        >
                            <span className="flex items-center gap-2">
                                <Badge variant="outline">
                                    {
                                        PROMOTION_ELIGIBILITY_SCOPE_OPTIONS.find(
                                            (o) =>
                                                o.value === eligibility.scope,
                                        )?.label
                                    }
                                </Badge>
                                <span>
                                    {eligibilityLabel(eligibility, lookups)}
                                </span>
                                {eligibility.service_zone && (
                                    <span className="text-muted-foreground text-xs">
                                        · {eligibility.service_zone.name} only
                                    </span>
                                )}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => remove(eligibility.id)}
                            >
                                Remove
                            </Button>
                        </div>
                    ))
                )}
            </div>

            <AddEligibilityForm promotion={promotion} lookups={lookups} />
        </div>
    );
}

function PromotionFormDialog({
    promotion,
    lookups,
}: {
    promotion?: Promotion;
    lookups: CatalogueLookups;
}) {
    const isEdit = !!promotion;
    const [open, setOpen] = useState(false);

    const form = useForm<PromotionFormData>({
        name: promotion?.name ?? '',
        type: promotion?.type ?? 'percentage',
        valueInput:
            promotion === undefined
                ? ''
                : promotion.type === 'percentage'
                  ? String(promotion.value)
                  : centsToDollarsInput(promotion.value),
        starts_at: promotion?.starts_at ?? '',
        ends_at: promotion?.ends_at ?? '',
        usage_limit:
            promotion?.usage_limit === null ||
            promotion?.usage_limit === undefined
                ? ''
                : String(promotion.usage_limit),
        stock_limit:
            promotion?.stock_limit === null ||
            promotion?.stock_limit === undefined
                ? ''
                : String(promotion.stock_limit),
        stackable: promotion?.stackable ?? false,
        status: promotion?.status ?? 'draft',
    });

    // `value` isn't a field in `PromotionFormData` (the client field is
    // `valueInput`, converted to `value` only at submit time — see
    // `submit()` below), so the server's 422 `value` error key doesn't type
    // against `form.errors`'s `PromotionFormData`-keyed shape. Same cast
    // pattern as `OrderShow`'s `RefundDialog` for its `idempotency_key`
    // error (a header field, not a form field).
    const valueError = (form.errors as Record<string, string | undefined>)
        .value;

    const isPercentage = form.data.type === 'percentage';
    const isGrouped =
        form.data.type === 'bundle' ||
        form.data.type === 'buy_x_get_y' ||
        form.data.type === 'four_for_three';

    const submit = () => {
        const payload = {
            name: form.data.name,
            type: form.data.type,
            value: isPercentage
                ? Number.parseInt(form.data.valueInput, 10) || 0
                : dollarsInputToCents(form.data.valueInput),
            starts_at: form.data.starts_at,
            ends_at: form.data.ends_at,
            usage_limit: form.data.usage_limit || null,
            stock_limit: form.data.stock_limit || null,
            stackable: form.data.stackable,
            status: form.data.status,
        };

        form.transform(() => payload);

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
            form.put(PromotionController.update(promotion.id).url, options);
        } else {
            form.post(PromotionController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New campaign'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit
                            ? `Edit ${promotion.name}`
                            : 'New promotion campaign'}
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
                        <p className="text-muted-foreground text-xs">
                            Customer-facing display name (e.g. "4 for 3 — Select
                            Bridgestone").
                        </p>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="type">Type</Label>
                            <Select
                                value={form.data.type}
                                onValueChange={(v) =>
                                    form.setData('type', v as PromotionType)
                                }
                            >
                                <SelectTrigger id="type" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {PROMOTION_TYPE_OPTIONS.map((o) => (
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
                            <Label htmlFor="value">
                                {isPercentage
                                    ? 'Discount (%)'
                                    : isGrouped
                                      ? 'Value (unused by this type)'
                                      : 'Discount per unit ($)'}
                            </Label>
                            <Input
                                id="value"
                                type="number"
                                min={isPercentage ? 1 : 0}
                                max={isPercentage ? 100 : undefined}
                                step={isPercentage ? 1 : 0.01}
                                value={form.data.valueInput}
                                onChange={(e) =>
                                    form.setData('valueInput', e.target.value)
                                }
                            />
                            <InputError message={valueError} />
                        </div>
                    </div>
                    {isGrouped && (
                        <p className="text-muted-foreground -mt-2 text-xs">
                            4-for-3/bundle/buy-X-get-Y discounts are computed
                            entirely from the pooled-unit grouping algorithm,
                            not from this value — the schema still requires a
                            non-negative number here (leave blank for 0), but it
                            has no effect on the discount. Flagged as a genuine
                            data-model gap, not silently hidden.
                        </p>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="starts_at">Starts</Label>
                            <Input
                                id="starts_at"
                                type="date"
                                value={form.data.starts_at}
                                onChange={(e) =>
                                    form.setData('starts_at', e.target.value)
                                }
                            />
                            <InputError message={form.errors.starts_at} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ends_at">Ends</Label>
                            <Input
                                id="ends_at"
                                type="date"
                                value={form.data.ends_at}
                                onChange={(e) =>
                                    form.setData('ends_at', e.target.value)
                                }
                            />
                            <InputError message={form.errors.ends_at} />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="usage_limit">
                                Usage limit (optional)
                            </Label>
                            <Input
                                id="usage_limit"
                                type="number"
                                min={1}
                                placeholder="Unlimited"
                                value={form.data.usage_limit}
                                onChange={(e) =>
                                    form.setData('usage_limit', e.target.value)
                                }
                            />
                            <InputError message={form.errors.usage_limit} />
                            <p className="text-muted-foreground text-xs">
                                Total redemptions across all customers, ever.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="stock_limit">
                                Stock limit (optional)
                            </Label>
                            <Input
                                id="stock_limit"
                                type="number"
                                min={1}
                                placeholder="Unlimited"
                                value={form.data.stock_limit}
                                onChange={(e) =>
                                    form.setData('stock_limit', e.target.value)
                                }
                            />
                            <InputError message={form.errors.stock_limit} />
                            <p className="text-muted-foreground text-xs">
                                Tighter "first N at this price" cap, held with a
                                TTL at booking time.
                            </p>
                        </div>
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={form.data.stackable}
                            onCheckedChange={(checked) =>
                                form.setData('stackable', checked === true)
                            }
                        />
                        Stackable (applies on top of other promotions, exempt
                        from mutual-exclusivity)
                    </label>
                    <InputError message={form.errors.stackable} />

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

                    {isEdit ? (
                        <EligibilityEditor
                            promotion={promotion}
                            lookups={lookups}
                        />
                    ) : (
                        <p className="text-muted-foreground text-xs">
                            Save the campaign first, then reopen it here to add
                            eligibility rules.
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create campaign'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeletePromotionButton({ promotion }: { promotion: Promotion }) {
    const destroy = () => {
        if (!confirm(`Delete the promotion "${promotion.name}"?`)) {
            return;
        }

        router.delete(PromotionController.destroy(promotion.id).url, {
            preserveScroll: true,
        });
    };

    return (
        <Button variant="outline" size="sm" onClick={destroy}>
            Delete
        </Button>
    );
}

export default function PromotionsCampaignsIndex({
    promotions,
    brands,
    tyreModels,
    tyreVariants,
    serviceZones,
}: {
    promotions: Promotion[];
} & CatalogueLookups) {
    const lookups: CatalogueLookups = {
        brands,
        tyreModels,
        tyreVariants,
        serviceZones,
    };

    return (
        <>
            <Head title="Promotion campaigns" />

            <div className="flex justify-end">
                <Can permission="promotions.manage">
                    <PromotionFormDialog lookups={lookups} />
                </Can>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Campaigns</CardTitle>
                    <CardDescription>
                        Auto-applied promotions — no customer-typed promo codes.
                        Mutually exclusive by default; the highest
                        total-discount match wins unless marked stackable.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {promotions.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No promotions yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">Name</th>
                                    <th className="py-2 font-medium">Type</th>
                                    <th className="py-2 font-medium">Value</th>
                                    <th className="py-2 font-medium">Dates</th>
                                    <th className="py-2 font-medium">Usage</th>
                                    <th className="py-2 font-medium">
                                        Eligibility
                                    </th>
                                    <th className="py-2 font-medium">Status</th>
                                    <th className="py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {promotions.map((promotion) => (
                                    <tr
                                        key={promotion.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 font-medium">
                                            {promotion.name}
                                            {promotion.stackable && (
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-2"
                                                >
                                                    Stackable
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="py-2 capitalize">
                                            {promotion.type.replace(/_/g, ' ')}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {promotion.type === 'percentage'
                                                ? `${promotion.value}%`
                                                : formatCents(promotion.value)}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {promotion.starts_at} –{' '}
                                            {promotion.ends_at}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {promotion.usage_count}
                                            {promotion.usage_limit
                                                ? `/${promotion.usage_limit}`
                                                : ''}
                                            {promotion.stock_limit && (
                                                <div className="text-xs">
                                                    stock cap:{' '}
                                                    {promotion.stock_limit}
                                                </div>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {promotion.eligibilities?.length ??
                                                0}{' '}
                                            rule(s)
                                        </td>
                                        <td className="py-2">
                                            <Badge
                                                variant={statusBadgeVariant(
                                                    promotion.status,
                                                )}
                                            >
                                                {promotion.status}
                                            </Badge>
                                        </td>
                                        <td className="space-x-2 py-2 text-right whitespace-nowrap">
                                            <Can permission="promotions.manage">
                                                <PromotionFormDialog
                                                    promotion={promotion}
                                                    lookups={lookups}
                                                />
                                                <DeletePromotionButton
                                                    promotion={promotion}
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

PromotionsCampaignsIndex.layout = {
    breadcrumbs: [
        { title: 'Promotions', href: PromotionController.index().url },
        { title: 'Campaigns', href: PromotionController.index().url },
    ] satisfies BreadcrumbItem[],
};
