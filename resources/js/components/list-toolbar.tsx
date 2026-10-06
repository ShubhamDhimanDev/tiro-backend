import { Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const ALL = '__all__';

type FilterValue = string | number | boolean | null | undefined;

type FilterConfig<T> = {
    label: string;
    get: (item: T) => FilterValue;
    /** Optional display text for a raw value; defaults to the value itself. */
    format?: (value: string) => string;
};

type FilterOption = { value: string; label: string };

type ToolbarFilter = {
    key: string;
    label: string;
    value: string;
    options: FilterOption[];
    onChange: (value: string) => void;
};

export type ListToolbarProps = {
    search: string;
    onSearch: (value: string) => void;
    placeholder: string;
    filters: ToolbarFilter[];
    shown: number;
    total: number;
    isFiltering: boolean;
    onReset: () => void;
};

/**
 * Client-side search + dropdown filters for an in-memory admin list. Filter
 * options are derived from the distinct values present in the data, so a
 * dropdown never offers a value that would match nothing.
 */
export function useListFilter<T>(
    items: T[],
    config: {
        placeholder?: string;
        searchText: (item: T) => Array<string | number | null | undefined>;
        filters?: Record<string, FilterConfig<T>>;
    },
): { filtered: T[]; toolbarProps: ListToolbarProps } {
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<Record<string, string>>({});

    const filterEntries = Object.entries(config.filters ?? {});

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return items.filter((item) => {
            if (needle !== '') {
                const haystack = config
                    .searchText(item)
                    .filter((v) => v !== null && v !== undefined)
                    .join(' ')
                    .toLowerCase();

                if (!haystack.includes(needle)) {
                    return false;
                }
            }

            return filterEntries.every(([key, filter]) => {
                const wanted = selected[key];

                return (
                    !wanted ||
                    wanted === ALL ||
                    String(filter.get(item) ?? '') === wanted
                );
            });
        });
        // `config` is rebuilt every render; its behaviour only depends on these.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items, search, selected]);

    const filters: ToolbarFilter[] = filterEntries.map(([key, filter]) => {
        const values = Array.from(
            new Set(
                items
                    .map((item) => filter.get(item))
                    .filter((v) => v !== null && v !== undefined && v !== '')
                    .map(String),
            ),
        ).sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));

        return {
            key,
            label: filter.label,
            value: selected[key] ?? ALL,
            options: values.map((value) => ({
                value,
                label: filter.format ? filter.format(value) : value,
            })),
            onChange: (value: string) =>
                setSelected((current) => ({ ...current, [key]: value })),
        };
    });

    const isFiltering =
        search.trim() !== '' ||
        Object.values(selected).some((v) => v && v !== ALL);

    return {
        filtered,
        toolbarProps: {
            search,
            onSearch: setSearch,
            placeholder: config.placeholder ?? 'Search…',
            filters: filters.filter((f) => f.options.length > 1),
            shown: filtered.length,
            total: items.length,
            isFiltering,
            onReset: () => {
                setSearch('');
                setSelected({});
            },
        },
    };
}

export function ListToolbar({
    search,
    onSearch,
    placeholder,
    filters,
    shown,
    total,
    isFiltering,
    onReset,
}: ListToolbarProps) {
    if (total === 0) {
        return null;
    }

    return (
        <div className="mb-4 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-full max-w-xs">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                    <Input
                        type="search"
                        value={search}
                        onChange={(e) => onSearch(e.target.value)}
                        placeholder={placeholder}
                        aria-label={placeholder}
                        className="pl-8"
                    />
                </div>
                {filters.map((filter) => (
                    <Select
                        key={filter.key}
                        value={filter.value}
                        onValueChange={filter.onChange}
                    >
                        <SelectTrigger
                            aria-label={filter.label}
                            className="w-44 capitalize"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                All {filter.label.toLowerCase()}
                            </SelectItem>
                            {filter.options.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                    className="capitalize"
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ))}
                {isFiltering && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onReset}
                    >
                        <X className="size-4" />
                        Clear
                    </Button>
                )}
            </div>
            <p className="text-muted-foreground text-xs" aria-live="polite">
                {isFiltering
                    ? shown === 0
                        ? 'No results match your search or filters.'
                        : `Showing ${shown} of ${total}`
                    : `${total} total`}
            </p>
        </div>
    );
}
