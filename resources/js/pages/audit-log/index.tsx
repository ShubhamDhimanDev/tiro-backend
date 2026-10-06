import { formatDateTime, isIsoDateTime } from '@/lib/date';
import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { FormEvent } from 'react';
import AuditLogController from '@/actions/App/Http/Controllers/Admin/AuditLogController';
import OrderController from '@/actions/App/Http/Controllers/Admin/Orders/OrderController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AuditLogEntry, AuditLogFilters } from '@/types/audit';
import type { Paginated } from '@/types/orders';
import type { BreadcrumbItem } from '@/types';

const ALL_VALUE = 'all';
const SYSTEM_VALUE = 'system';

/**
 * Friendly label per `auditable_type` FQCN — falls back to the class's
 * short name (last `\`-segment) for anything not in this map, so a future
 * addition to `AuditLogQueryService::AUDITABLE_TYPES` never silently
 * renders blank.
 */
const AUDITABLE_TYPE_LABELS: Record<string, string> = {
    'App\\Models\\Booking': 'Booking',
    'App\\Models\\Order': 'Order',
    'App\\Models\\Promotion': 'Promotion',
    'App\\Models\\PriceRule': 'Price rule',
    'App\\Models\\PriceGuaranteeClaim': 'Price guarantee claim',
    'App\\Models\\User': 'User',
    'Spatie\\Permission\\Models\\Role': 'Role',
    'App\\Models\\ContentPage': 'Content page',
    'App\\Models\\Faq': 'FAQ',
};

function auditableLabel(type: string): string {
    return AUDITABLE_TYPE_LABELS[type] ?? type.split('\\').pop() ?? type;
}

/**
 * A link into the record's own admin screen, only for types that actually
 * have a per-record detail route today — `Order` is the only one (see
 * `OrderController::show()`). Everything else in
 * `AuditLogQueryService::AUDITABLE_TYPES` only has an index/list screen (no
 * per-ID deep link), so this deliberately renders plain text for those
 * rather than a link to nowhere specific.
 */
function auditableHref(type: string, id: number): string | null {
    if (type === 'App\\Models\\Order') {
        return OrderController.show(id).url;
    }

    return null;
}

function formatDiffValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'string') {
        return isIsoDateTime(value) ? formatDateTime(value) : value;
    }

    if (
        typeof value === 'number' ||
        typeof value === 'boolean' ||
        typeof value === 'bigint'
    ) {
        return value.toString();
    }

    // Objects, arrays, and anything else — `before`/`after` are decoded
    // JSON (see `AuditLog`'s `array` casts), so this only ever runs for
    // plain data structures with no custom `toString()`.
    return JSON.stringify(value);
}

function DiffTable({
    before,
    after,
}: {
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
}) {
    const keys = Array.from(
        new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})]),
    ).sort();

    if (keys.length === 0) {
        return (
            <p className="text-muted-foreground text-xs">
                No field-level changes recorded.
            </p>
        );
    }

    return (
        <table className="w-full text-xs">
            <thead>
                <tr className="text-left">
                    <th className="py-1 pr-4 font-medium">Field</th>
                    <th className="py-1 pr-4 font-medium">Before</th>
                    <th className="py-1 font-medium">After</th>
                </tr>
            </thead>
            <tbody>
                {keys.map((key) => (
                    <tr key={key} className="border-t">
                        <td className="py-1 pr-4 font-mono">{key}</td>
                        <td className="text-muted-foreground py-1 pr-4">
                            {formatDiffValue(before?.[key])}
                        </td>
                        <td className="py-1">
                            {formatDiffValue(after?.[key])}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function applyFilters(
    next: Partial<Record<string, string | null>>,
    current: AuditLogFilters,
) {
    router.get(
        AuditLogController.index().url,
        {
            actor_id: current.actor_id,
            auditable_type: current.auditable_type,
            action: current.action,
            from: current.from,
            to: current.to,
            ...next,
        },
        { preserveState: true, preserveScroll: true },
    );
}

export default function AuditLogIndex({
    logs,
    filters,
    actors,
    auditableTypes,
}: {
    logs: Paginated<AuditLogEntry>;
    filters: AuditLogFilters;
    actors: { id: number; name: string }[];
    auditableTypes: string[];
}) {
    const [actionInput, setActionInput] = useState(filters.action ?? '');
    const [expandedId, setExpandedId] = useState<number | null>(null);

    const submitAction = (e: FormEvent) => {
        e.preventDefault();
        applyFilters({ action: actionInput || null }, filters);
    };

    const goToPage = (page: number) => {
        router.get(
            AuditLogController.index().url,
            { ...filters, page },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Audit log" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Audit log"
                    description="Every tracked create/update/delete across Bookings, Orders, Promotions, Price rules, Price-guarantee claims, Users, Roles, Content pages, and FAQs."
                />

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 pt-6">
                        <div className="grid gap-2">
                            <Label htmlFor="filter-actor">Actor</Label>
                            <Select
                                value={filters.actor_id ?? ALL_VALUE}
                                onValueChange={(v) =>
                                    applyFilters(
                                        {
                                            actor_id:
                                                v === ALL_VALUE ? null : v,
                                        },
                                        filters,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="filter-actor"
                                    className="w-56"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_VALUE}>
                                        All actors
                                    </SelectItem>
                                    <SelectItem value={SYSTEM_VALUE}>
                                        System
                                    </SelectItem>
                                    {actors.map((actor) => (
                                        <SelectItem
                                            key={actor.id}
                                            value={String(actor.id)}
                                        >
                                            {actor.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="filter-auditable-type">
                                Record type
                            </Label>
                            <Select
                                value={filters.auditable_type ?? ALL_VALUE}
                                onValueChange={(v) =>
                                    applyFilters(
                                        {
                                            auditable_type:
                                                v === ALL_VALUE ? null : v,
                                        },
                                        filters,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="filter-auditable-type"
                                    className="w-56"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_VALUE}>
                                        All record types
                                    </SelectItem>
                                    {auditableTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {auditableLabel(type)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <form onSubmit={submitAction} className="grid gap-2">
                            <Label htmlFor="filter-action">Action</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="filter-action"
                                    className="w-56"
                                    placeholder="e.g. bookings.cancelled"
                                    value={actionInput}
                                    onChange={(e) =>
                                        setActionInput(e.target.value)
                                    }
                                />
                                <Button type="submit" variant="outline">
                                    Search
                                </Button>
                            </div>
                        </form>

                        <div className="grid gap-2">
                            <Label htmlFor="filter-from">From</Label>
                            <Input
                                id="filter-from"
                                type="date"
                                className="w-40"
                                value={filters.from ?? ''}
                                onChange={(e) =>
                                    applyFilters(
                                        { from: e.target.value || null },
                                        filters,
                                    )
                                }
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="filter-to">To</Label>
                            <Input
                                id="filter-to"
                                type="date"
                                className="w-40"
                                value={filters.to ?? ''}
                                onChange={(e) =>
                                    applyFilters(
                                        { to: e.target.value || null },
                                        filters,
                                    )
                                }
                            />
                        </div>

                        {(filters.actor_id ||
                            filters.auditable_type ||
                            filters.action ||
                            filters.from ||
                            filters.to) && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setActionInput('');
                                    router.get(AuditLogController.index().url);
                                }}
                            >
                                Clear filters
                            </Button>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="pt-6">
                        {logs.data.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No audit log entries match this filter.
                            </p>
                        ) : (
                            <>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="py-2 font-medium">
                                                When
                                            </th>
                                            <th className="py-2 font-medium">
                                                Actor
                                            </th>
                                            <th className="py-2 font-medium">
                                                Action
                                            </th>
                                            <th className="py-2 font-medium">
                                                Record
                                            </th>
                                            <th className="py-2 font-medium" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {logs.data.map((log) => {
                                            const isExpanded =
                                                expandedId === log.id;
                                            const href = auditableHref(
                                                log.auditable_type,
                                                log.auditable_id,
                                            );

                                            return (
                                                <Fragment key={log.id}>
                                                    <tr className="border-b last:border-0">
                                                        <td className="text-muted-foreground py-2 whitespace-nowrap">
                                                            {new Date(
                                                                log.created_at,
                                                            ).toLocaleString(
                                                                'en-AU',
                                                            )}
                                                        </td>
                                                        <td className="py-2">
                                                            {log.actor
                                                                ?.name ?? (
                                                                <Badge variant="secondary">
                                                                    System
                                                                </Badge>
                                                            )}
                                                        </td>
                                                        <td className="py-2 font-mono text-xs">
                                                            {log.action}
                                                        </td>
                                                        <td className="py-2">
                                                            {href ? (
                                                                <Link
                                                                    href={href}
                                                                    className="text-primary underline underline-offset-2"
                                                                >
                                                                    {auditableLabel(
                                                                        log.auditable_type,
                                                                    )}{' '}
                                                                    #
                                                                    {
                                                                        log.auditable_id
                                                                    }
                                                                </Link>
                                                            ) : (
                                                                <span>
                                                                    {auditableLabel(
                                                                        log.auditable_type,
                                                                    )}{' '}
                                                                    #
                                                                    {
                                                                        log.auditable_id
                                                                    }
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="py-2 text-right">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setExpandedId(
                                                                        isExpanded
                                                                            ? null
                                                                            : log.id,
                                                                    )
                                                                }
                                                            >
                                                                {isExpanded
                                                                    ? 'Hide diff'
                                                                    : 'View diff'}
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                    {isExpanded && (
                                                        <tr className="border-b last:border-0">
                                                            <td
                                                                colSpan={5}
                                                                className="bg-muted/30 py-3"
                                                            >
                                                                <DiffTable
                                                                    before={
                                                                        log.before
                                                                    }
                                                                    after={
                                                                        log.after
                                                                    }
                                                                />
                                                            </td>
                                                        </tr>
                                                    )}
                                                </Fragment>
                                            );
                                        })}
                                    </tbody>
                                </table>

                                <div className="text-muted-foreground flex items-center justify-between pt-4 text-sm">
                                    <span>
                                        Showing {logs.from ?? 0}–{logs.to ?? 0}{' '}
                                        of {logs.total}
                                    </span>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={logs.current_page <= 1}
                                            onClick={() =>
                                                goToPage(logs.current_page - 1)
                                            }
                                        >
                                            Previous
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                logs.current_page >=
                                                logs.last_page
                                            }
                                            onClick={() =>
                                                goToPage(logs.current_page + 1)
                                            }
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AuditLogIndex.layout = {
    breadcrumbs: [
        { title: 'Audit log', href: AuditLogController.index().url },
    ] satisfies BreadcrumbItem[],
};
