import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import BrandController from '@/actions/App/Http/Controllers/Admin/Products/BrandController';
import ProductExportController from '@/actions/App/Http/Controllers/Admin/Products/ProductExportController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { BreadcrumbItem } from '@/types';

type Option<T extends string | number = string> = { value: T; label: string };

type ExportColumn = {
    key: string;
    label: string;
    group: string;
    default: boolean;
};

type ExportFilters = {
    brand_ids: number[];
    categories: string[];
    tyre_types: string[];
    constructions: string[];
    sidewalls: string[];
    statuses: string[];
    model_statuses: string[];
    rim_diameters: number[];
    run_flat: 'any' | 'yes' | 'no';
    in_stock_only: boolean;
    price_min: string;
    price_max: string;
    search: string;
};

type PageProps = {
    brands: { id: number; name: string }[];
    columns: ExportColumn[];
    options: {
        categories: Option[];
        tyreTypes: Option[];
        constructions: Option[];
        sidewalls: Option[];
        statuses: Option[];
        rimDiameters: number[];
    };
    selected: {
        columns: string[];
        filters: Partial<
            Omit<ExportFilters, 'run_flat' | 'price_min' | 'price_max'>
        > & {
            run_flat?: 'yes' | 'no' | null;
            price_min?: string | number | null;
            price_max?: string | number | null;
        };
    };
    matchingCount?: number;
    totalCount?: number;
};

const ARRAY_FILTERS = [
    'brand_ids',
    'categories',
    'tyre_types',
    'constructions',
    'sidewalls',
    'statuses',
    'model_statuses',
    'rim_diameters',
] as const;

/** Serialise filters (and optionally columns) the way the controller's request validates them. */
function toQueryString(filters: ExportFilters, columns?: string[]): string {
    const params = new URLSearchParams();

    columns?.forEach((key) => params.append('columns[]', key));

    ARRAY_FILTERS.forEach((name) => {
        (filters[name] as (string | number)[]).forEach((value) =>
            params.append(`${name}[]`, String(value)),
        );
    });

    if (filters.run_flat !== 'any') {
        params.set('run_flat', filters.run_flat);
    }

    if (filters.in_stock_only) {
        params.set('in_stock_only', '1');
    }

    (['price_min', 'price_max', 'search'] as const).forEach((name) => {
        if (filters[name].trim() !== '') {
            params.set(name, filters[name].trim());
        }
    });

    return params.toString();
}

function hasActiveFilters(filters: ExportFilters): boolean {
    return toQueryString(filters) !== '';
}

const EMPTY_FILTERS: ExportFilters = {
    brand_ids: [],
    categories: [],
    tyre_types: [],
    constructions: [],
    sidewalls: [],
    statuses: [],
    model_statuses: [],
    rim_diameters: [],
    run_flat: 'any',
    in_stock_only: false,
    price_min: '',
    price_max: '',
    search: '',
};

function initialFilters(
    selected: PageProps['selected']['filters'],
): ExportFilters {
    return {
        ...EMPTY_FILTERS,
        ...Object.fromEntries(
            Object.entries(selected).filter(([, value]) => value != null),
        ),
        run_flat: selected.run_flat ?? 'any',
        price_min: selected.price_min != null ? String(selected.price_min) : '',
        price_max: selected.price_max != null ? String(selected.price_max) : '',
        search: selected.search ?? '',
        in_stock_only: !!selected.in_stock_only,
    };
}

function toggle<T>(list: T[], value: T): T[] {
    return list.includes(value)
        ? list.filter((item) => item !== value)
        : [...list, value];
}

function CheckList<T extends string | number>({
    legend,
    options,
    value,
    onChange,
    scroll = false,
}: {
    legend: string;
    options: Option<T>[];
    value: T[];
    onChange: (next: T[]) => void;
    scroll?: boolean;
}) {
    return (
        <fieldset className="grid gap-2">
            <div className="flex items-center justify-between">
                <legend className="text-sm leading-none font-medium">
                    {legend}
                    {value.length > 0 && (
                        <span className="text-muted-foreground ml-2 font-normal">
                            {value.length} selected
                        </span>
                    )}
                </legend>
                {value.length > 0 && (
                    <Button
                        type="button"
                        variant="link"
                        size="sm"
                        className="h-auto p-0 text-xs"
                        onClick={() => onChange([])}
                    >
                        Clear
                    </Button>
                )}
            </div>
            <div
                className={
                    scroll
                        ? 'grid max-h-48 gap-2 overflow-y-auto rounded-md border p-3 sm:grid-cols-2'
                        : 'flex flex-wrap gap-x-4 gap-y-2'
                }
            >
                {options.map((option) => {
                    const id = `${legend}-${option.value}`;

                    return (
                        <div
                            key={option.value}
                            className="flex items-center gap-2"
                        >
                            <Checkbox
                                id={id}
                                checked={value.includes(option.value)}
                                onCheckedChange={() =>
                                    onChange(toggle(value, option.value))
                                }
                            />
                            <Label htmlFor={id} className="font-normal">
                                {option.label}
                            </Label>
                        </div>
                    );
                })}
            </div>
        </fieldset>
    );
}

export default function ProductExport({
    brands,
    columns,
    options,
    selected,
    matchingCount,
    totalCount,
}: PageProps) {
    const { errors } = usePage().props as { errors?: Record<string, string> };

    const defaultColumns = useMemo(
        () => columns.filter((c) => c.default).map((c) => c.key),
        [columns],
    );

    const [filters, setFilters] = useState<ExportFilters>(() =>
        initialFilters(selected.filters),
    );
    const [chosenColumns, setChosenColumns] = useState<string[]>(
        selected.columns.length > 0 ? selected.columns : defaultColumns,
    );

    const setFilter = <K extends keyof ExportFilters>(
        key: K,
        value: ExportFilters[K],
    ) => setFilters((current) => ({ ...current, [key]: value }));

    // Refresh the "N rows match" count (only that prop) whenever filters change.
    const filterQuery = toQueryString(filters);
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = setTimeout(() => {
            const base = ProductExportController.index().url;

            router.get(
                filterQuery ? `${base}?${filterQuery}` : base,
                {},
                {
                    only: ['matchingCount', 'errors'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [filterQuery]);

    const groups = useMemo(() => {
        const byGroup = new Map<string, ExportColumn[]>();

        columns.forEach((column) => {
            byGroup.set(column.group, [
                ...(byGroup.get(column.group) ?? []),
                column,
            ]);
        });

        return [...byGroup.entries()];
    }, [columns]);

    const canDownload = chosenColumns.length > 0 && matchingCount !== 0;
    const downloadUrl = `${ProductExportController.download().url}?${toQueryString(filters, chosenColumns)}`;

    return (
        <>
            <Head title="Export products" />

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Export products to Excel</CardTitle>
                        <CardDescription>
                            One row per size variant (SKU), with its tyre model
                            and brand details. Choose which products to include
                            and which columns the sheet contains.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-wrap items-center justify-between gap-4">
                        <div className="text-sm">
                            <span className="text-2xl font-semibold tabular-nums">
                                {matchingCount?.toLocaleString() ?? '—'}
                            </span>{' '}
                            <span className="text-muted-foreground">
                                of {totalCount?.toLocaleString() ?? '—'} size
                                variants match · {chosenColumns.length} column
                                {chosenColumns.length === 1 ? '' : 's'}
                            </span>
                        </div>

                        {canDownload ? (
                            <Button asChild>
                                <a href={downloadUrl}>Download .xlsx</a>
                            </Button>
                        ) : (
                            <Button disabled>Download .xlsx</Button>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Which products</CardTitle>
                        <CardDescription>
                            Leave a filter empty to include everything for it.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="search">Search</Label>
                            <Input
                                id="search"
                                placeholder="SKU, EAN, model or brand name…"
                                value={filters.search}
                                onChange={(e) =>
                                    setFilter('search', e.target.value)
                                }
                            />
                            <InputError message={errors?.search} />
                        </div>

                        <CheckList
                            legend="Brands"
                            scroll
                            options={brands.map((b) => ({
                                value: b.id,
                                label: b.name,
                            }))}
                            value={filters.brand_ids}
                            onChange={(next) => setFilter('brand_ids', next)}
                        />

                        <div className="grid gap-6 md:grid-cols-2">
                            <CheckList
                                legend="Category"
                                options={options.categories}
                                value={filters.categories}
                                onChange={(next) =>
                                    setFilter('categories', next)
                                }
                            />
                            <CheckList
                                legend="Tyre type"
                                options={options.tyreTypes}
                                value={filters.tyre_types}
                                onChange={(next) =>
                                    setFilter('tyre_types', next)
                                }
                            />
                            <CheckList
                                legend="Construction"
                                options={options.constructions}
                                value={filters.constructions}
                                onChange={(next) =>
                                    setFilter('constructions', next)
                                }
                            />
                            <CheckList
                                legend="Sidewall"
                                options={options.sidewalls}
                                value={filters.sidewalls}
                                onChange={(next) =>
                                    setFilter('sidewalls', next)
                                }
                            />
                            <CheckList
                                legend="Variant status"
                                options={options.statuses}
                                value={filters.statuses}
                                onChange={(next) => setFilter('statuses', next)}
                            />
                            <CheckList
                                legend="Model status"
                                options={options.statuses}
                                value={filters.model_statuses}
                                onChange={(next) =>
                                    setFilter('model_statuses', next)
                                }
                            />
                        </div>

                        <CheckList
                            legend="Rim size (inches)"
                            options={options.rimDiameters.map((rim) => ({
                                value: rim,
                                label: `${rim}"`,
                            }))}
                            value={filters.rim_diameters}
                            onChange={(next) =>
                                setFilter('rim_diameters', next)
                            }
                        />

                        <div className="grid gap-4 md:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="run_flat">Run-flat</Label>
                                <Select
                                    value={filters.run_flat}
                                    onValueChange={(v) =>
                                        setFilter(
                                            'run_flat',
                                            v as ExportFilters['run_flat'],
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="run_flat"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="any">Any</SelectItem>
                                        <SelectItem value="yes">
                                            Run-flat only
                                        </SelectItem>
                                        <SelectItem value="no">
                                            Not run-flat
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="price_min">Min price ($)</Label>
                                <Input
                                    id="price_min"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={filters.price_min}
                                    onChange={(e) =>
                                        setFilter('price_min', e.target.value)
                                    }
                                />
                                <InputError message={errors?.price_min} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="price_max">Max price ($)</Label>
                                <Input
                                    id="price_max"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={filters.price_max}
                                    onChange={(e) =>
                                        setFilter('price_max', e.target.value)
                                    }
                                />
                                <InputError message={errors?.price_max} />
                            </div>

                            <div className="flex items-end gap-2 pb-2">
                                <Checkbox
                                    id="in_stock_only"
                                    checked={filters.in_stock_only}
                                    onCheckedChange={(checked) =>
                                        setFilter(
                                            'in_stock_only',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="in_stock_only"
                                    className="font-normal"
                                >
                                    Only with stock available
                                </Label>
                            </div>
                        </div>

                        {hasActiveFilters(filters) && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setFilters(EMPTY_FILTERS)}
                            >
                                Reset filters
                            </Button>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Which columns</CardTitle>
                        <CardDescription>
                            Columns appear in the sheet in the order listed
                            here.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setChosenColumns(columns.map((c) => c.key))
                                }
                            >
                                Select all
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setChosenColumns(defaultColumns)}
                            >
                                Reset to defaults
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setChosenColumns([])}
                            >
                                Clear
                            </Button>
                        </div>

                        {chosenColumns.length === 0 && (
                            <InputError message="Select at least one column to export." />
                        )}

                        <div className="grid gap-6 md:grid-cols-2">
                            {groups.map(([group, groupColumns]) => {
                                const keys = groupColumns.map((c) => c.key);
                                const allChosen = keys.every((key) =>
                                    chosenColumns.includes(key),
                                );

                                return (
                                    <fieldset
                                        key={group}
                                        className="grid gap-2"
                                    >
                                        <div className="flex items-center justify-between">
                                            <legend className="text-sm leading-none font-medium">
                                                {group}
                                            </legend>
                                            <Button
                                                type="button"
                                                variant="link"
                                                size="sm"
                                                className="h-auto p-0 text-xs"
                                                onClick={() =>
                                                    setChosenColumns(
                                                        (current) =>
                                                            allChosen
                                                                ? current.filter(
                                                                      (key) =>
                                                                          !keys.includes(
                                                                              key,
                                                                          ),
                                                                  )
                                                                : [
                                                                      ...current,
                                                                      ...keys.filter(
                                                                          (
                                                                              key,
                                                                          ) =>
                                                                              !current.includes(
                                                                                  key,
                                                                              ),
                                                                      ),
                                                                  ],
                                                    )
                                                }
                                            >
                                                {allChosen
                                                    ? 'Deselect group'
                                                    : 'Select group'}
                                            </Button>
                                        </div>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {groupColumns.map((column) => (
                                                <div
                                                    key={column.key}
                                                    className="flex items-center gap-2"
                                                >
                                                    <Checkbox
                                                        id={`column-${column.key}`}
                                                        checked={chosenColumns.includes(
                                                            column.key,
                                                        )}
                                                        onCheckedChange={() =>
                                                            setChosenColumns(
                                                                (current) =>
                                                                    toggle(
                                                                        current,
                                                                        column.key,
                                                                    ),
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`column-${column.key}`}
                                                        className="font-normal"
                                                    >
                                                        {column.label}
                                                    </Label>
                                                </div>
                                            ))}
                                        </div>
                                    </fieldset>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ProductExport.layout = {
    breadcrumbs: [
        { title: 'Products', href: BrandController.index().url },
        {
            title: 'Export',
            href: ProductExportController.index().url,
        },
    ] satisfies BreadcrumbItem[],
};
