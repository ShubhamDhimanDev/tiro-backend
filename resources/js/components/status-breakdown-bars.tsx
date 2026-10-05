import { Badge } from '@/components/ui/badge';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

/**
 * A magnitude-by-category breakdown, rendered as a labeled horizontal-bar
 * list rather than a pie/donut — every category is direct-labeled (never
 * color-alone), one proportional bar per row sized against the row total,
 * dependency-free (no charting library installed — see `StatTile`'s
 * docblock). Reused by every reporting dashboard's "by status" breakdown.
 */
export function StatusBreakdownBars({
    data,
    labels,
    badgeVariant,
}: {
    data: Record<string, number>;
    labels: Record<string, string>;
    badgeVariant: (status: string) => BadgeVariant;
}) {
    const entries = Object.entries(data);
    const total = entries.reduce((sum, [, count]) => sum + count, 0);

    if (entries.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                No data in this range.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            {entries.map(([status, count]) => {
                const pct = total > 0 ? Math.round((count / total) * 100) : 0;

                return (
                    <div
                        key={status}
                        className="flex items-center gap-3 text-sm"
                    >
                        <Badge
                            variant={badgeVariant(status)}
                            className="w-36 shrink-0 justify-center"
                        >
                            {labels[status] ?? status}
                        </Badge>
                        <div className="bg-muted h-2 flex-1 overflow-hidden rounded-full">
                            <div
                                className="bg-primary h-full rounded-full"
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                        <span className="text-muted-foreground w-24 shrink-0 text-right tabular-nums">
                            {count} ({pct}%)
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
