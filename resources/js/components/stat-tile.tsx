import { cn } from '@/lib/utils';

/**
 * A single labeled figure — reused across every reporting dashboard. No
 * charting library is installed in this project (see package.json) and none
 * was added for this; large standalone figures use plain proportional
 * numerals, matching the shadcn/Tailwind design tokens already in use
 * elsewhere in the admin panel rather than a separately-specified palette.
 */
export function StatTile({
    label,
    value,
    hint,
    emphasis = false,
}: {
    label: string;
    value: string;
    hint?: string;
    emphasis?: boolean;
}) {
    return (
        <div className="rounded-lg border p-4">
            <p className="text-muted-foreground text-xs font-medium">{label}</p>
            <p
                className={cn(
                    'mt-1 font-semibold tabular-nums',
                    emphasis ? 'text-3xl' : 'text-2xl',
                )}
            >
                {value}
            </p>
            {hint && (
                <p className="text-muted-foreground mt-1 text-xs">{hint}</p>
            )}
        </div>
    );
}
