import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DAYS_OF_WEEK } from '@/types/locations';
import type { OperatingHours } from '@/types/locations';

/**
 * Per-weekday editor for `ServiceZone.operating_hours`, matching the locked
 * `{"mon": {"open": "HH:mm", "close": "HH:mm"}, ..., "sun": null}` shape
 * (see docs/architecture/01-data-model.md). Not a raw JSON textarea — every
 * day is its own open/closed row with native `<input type="time">`
 * controls for the hours.
 *
 * New UI pattern (no existing sibling to copy) — reusable anywhere else an
 * `operating_hours`-shaped value needs editing (e.g. a future
 * StockLocation hours field).
 */
export function OperatingHoursEditor({
    value,
    onChange,
    errors,
}: {
    value: OperatingHours;
    onChange: (next: OperatingHours) => void;
    errors?: Record<string, string | undefined>;
}) {
    const setDay = (day: keyof OperatingHours, open: boolean) => {
        onChange({
            ...value,
            [day]: open ? { open: '09:00', close: '17:00' } : null,
        });
    };

    const setTime = (
        day: keyof OperatingHours,
        field: 'open' | 'close',
        time: string,
    ) => {
        const current = value[day];

        onChange({
            ...value,
            [day]: {
                open: current?.open ?? '09:00',
                close: current?.close ?? '17:00',
                [field]: time,
            },
        });
    };

    return (
        <div className="space-y-2">
            {DAYS_OF_WEEK.map(([key, label]) => {
                const day = value[key];
                const isOpen = day !== null;
                const dayError =
                    errors?.[`operating_hours.${key}.open`] ??
                    errors?.[`operating_hours.${key}.close`];

                return (
                    <div
                        key={key}
                        className="flex flex-wrap items-center gap-3 rounded-md border px-3 py-2"
                    >
                        <div className="flex w-32 items-center gap-2">
                            <Checkbox
                                id={`hours-${key}-open`}
                                checked={isOpen}
                                onCheckedChange={(checked) =>
                                    setDay(key, checked === true)
                                }
                            />
                            <Label
                                htmlFor={`hours-${key}-open`}
                                className="font-normal"
                            >
                                {label}
                            </Label>
                        </div>

                        {isOpen ? (
                            <div className="flex items-center gap-2">
                                <Input
                                    type="time"
                                    className="w-32"
                                    value={day.open}
                                    onChange={(e) =>
                                        setTime(key, 'open', e.target.value)
                                    }
                                    aria-label={`${label} opening time`}
                                />
                                <span className="text-muted-foreground text-sm">
                                    to
                                </span>
                                <Input
                                    type="time"
                                    className="w-32"
                                    value={day.close}
                                    onChange={(e) =>
                                        setTime(key, 'close', e.target.value)
                                    }
                                    aria-label={`${label} closing time`}
                                />
                            </div>
                        ) : (
                            <span className="text-muted-foreground text-sm">
                                Closed
                            </span>
                        )}

                        {dayError && (
                            <span className="text-destructive w-full text-xs">
                                {dayError}
                            </span>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
