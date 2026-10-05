import { Deferred, router, useForm, usePoll } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DispatchBoardController from '@/actions/App/Http/Controllers/Admin/Bookings/DispatchBoardController';
import { BookingsSubNav } from '@/components/bookings-sub-nav';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Skeleton } from '@/components/ui/skeleton';
import { BOOKING_STATUS_LABELS, bookingStatusBadgeVariant } from '@/lib/enums';
import type {
    BookingLookup,
    DispatchAvailabilityCandidate,
    DispatchBooking,
    DispatchFilters,
    DispatchShift,
} from '@/types/bookings';
import type { BreadcrumbItem } from '@/types';

/** Mirrors `DispatchBoardController::MOVABLE_STATUSES`. */
const MOVABLE_STATUSES = ['pending_hold', 'confirmed'];

const ALL_VALUE = 'all';

const AUTO_TECHNICIAN_VALUE = 'auto';

function BoardSkeleton() {
    return (
        <div className="space-y-6">
            <Skeleton className="h-40 w-full" />
            <Skeleton className="h-72 w-full" />
        </div>
    );
}

function MoveBookingDialog({ booking }: { booking: DispatchBooking }) {
    const [open, setOpen] = useState(false);
    const [candidates, setCandidates] = useState<
        DispatchAvailabilityCandidate[] | null
    >(null);
    const [checking, setChecking] = useState(false);

    const form = useForm<{
        scheduled_date: string;
        slot_start: string;
        technician_id: string;
    }>({
        scheduled_date: booking.scheduled_date,
        slot_start: booking.slot_start.slice(0, 5),
        technician_id: AUTO_TECHNICIAN_VALUE,
    });

    // Live "who's actually free for this slot" preview — calls the same
    // SlotComputationService query the move itself will re-check under
    // lock, so this is a helpful preview, never the source of truth for
    // whether the move will succeed.
    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;
        setChecking(true);

        const timeout = setTimeout(() => {
            const url = DispatchBoardController.availability(booking.id, {
                query: {
                    scheduled_date: form.data.scheduled_date,
                    slot_start: form.data.slot_start,
                },
            }).url;

            fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) =>
                    response.ok ? response.json() : Promise.reject(response),
                )
                .then((json: { data: DispatchAvailabilityCandidate[] }) => {
                    if (!cancelled) {
                        setCandidates(json.data);
                    }
                })
                .catch(() => {
                    if (!cancelled) {
                        setCandidates([]);
                    }
                })
                .finally(() => {
                    if (!cancelled) {
                        setChecking(false);
                    }
                });
        }, 300);

        return () => {
            cancelled = true;
            clearTimeout(timeout);
        };
    }, [open, form.data.scheduled_date, form.data.slot_start, booking.id]);

    const submit = () => {
        const payload = {
            scheduled_date: form.data.scheduled_date,
            slot_start: form.data.slot_start,
            technician_id:
                form.data.technician_id === AUTO_TECHNICIAN_VALUE
                    ? null
                    : form.data.technician_id,
        };

        form.transform(() => payload);

        form.patch(DispatchBoardController.move(booking.id).url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Move
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Move booking #{booking.id}</DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="move-date">Date</Label>
                            <Input
                                id="move-date"
                                type="date"
                                value={form.data.scheduled_date}
                                onChange={(e) =>
                                    form.setData(
                                        'scheduled_date',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.scheduled_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="move-time">Start time</Label>
                            <Input
                                id="move-time"
                                type="time"
                                value={form.data.slot_start}
                                onChange={(e) =>
                                    form.setData('slot_start', e.target.value)
                                }
                            />
                            <InputError message={form.errors.slot_start} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="move-technician">Technician</Label>
                        <Select
                            value={form.data.technician_id}
                            onValueChange={(v) =>
                                form.setData('technician_id', v)
                            }
                        >
                            <SelectTrigger
                                id="move-technician"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={AUTO_TECHNICIAN_VALUE}>
                                    Auto (first available)
                                </SelectItem>
                                {(candidates ?? []).map((candidate) => (
                                    <SelectItem
                                        key={candidate.technician_id}
                                        value={String(candidate.technician_id)}
                                    >
                                        {candidate.technician_name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.technician_id} />
                        <p className="text-muted-foreground text-xs">
                            {checking
                                ? 'Checking availability…'
                                : candidates === null
                                  ? null
                                  : candidates.length === 0
                                    ? 'Nobody is free for this slot yet — try a different date/time.'
                                    : `${candidates.length} technician(s) free for this slot.`}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        Move booking
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function CancelBookingButton({ booking }: { booking: DispatchBooking }) {
    const cancel = () => {
        if (!confirm(`Cancel booking #${booking.id}?`)) {
            return;
        }

        router.post(
            DispatchBoardController.cancel(booking.id).url,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <Button variant="destructive" size="sm" onClick={cancel}>
            Cancel
        </Button>
    );
}

function tyreSummary(booking: DispatchBooking): string {
    return (booking.line_items ?? [])
        .map((item) => {
            const spec = item.tyre_variant;

            return spec
                ? `${item.quantity}x ${spec.width}/${spec.profile}R${spec.rim_diameter}`
                : `${item.quantity}x tyre`;
        })
        .join(', ');
}

function ShiftsTable({ shifts }: { shifts: DispatchShift[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Shifts ({shifts.length})</CardTitle>
            </CardHeader>
            <CardContent>
                {shifts.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No shifts for this filter.
                    </p>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="py-2 font-medium">Technician</th>
                                <th className="py-2 font-medium">Van</th>
                                <th className="py-2 font-medium">Zone</th>
                                <th className="py-2 font-medium">Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shifts.map((shift) => (
                                <tr
                                    key={shift.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="py-2 font-medium">
                                        {shift.technician?.name}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {shift.van?.name} ({shift.van?.rego})
                                        {shift.van?.has_alignment_equipment && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Alignment
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {shift.service_zone?.name}
                                    </td>
                                    <td className="py-2">
                                        {shift.shift_start.slice(0, 5)}–
                                        {shift.shift_end.slice(0, 5)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </CardContent>
        </Card>
    );
}

function BookingsTable({ bookings }: { bookings: DispatchBooking[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Bookings ({bookings.length})</CardTitle>
            </CardHeader>
            <CardContent>
                {bookings.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No bookings for this filter.
                    </p>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="py-2 font-medium">Time</th>
                                <th className="py-2 font-medium">Customer</th>
                                <th className="py-2 font-medium">Tyres</th>
                                <th className="py-2 font-medium">Technician</th>
                                <th className="py-2 font-medium">Van</th>
                                <th className="py-2 font-medium">Status</th>
                                <th className="py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {bookings.map((booking) => (
                                <tr
                                    key={booking.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="py-2 font-medium">
                                        {booking.slot_start.slice(0, 5)}–
                                        {booking.slot_end.slice(0, 5)}
                                    </td>
                                    <td className="py-2">
                                        {booking.customer?.name ?? (
                                            <span className="text-muted-foreground">
                                                Guest
                                            </span>
                                        )}
                                        {booking.customer?.mobile && (
                                            <div className="text-muted-foreground text-xs">
                                                {booking.customer.mobile}
                                            </div>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {tyreSummary(booking) || '—'}
                                        {booking.addons &&
                                            booking.addons.length > 0 && (
                                                <div className="flex flex-wrap gap-1 pt-1">
                                                    {booking.addons.map(
                                                        (addon) => (
                                                            <Badge
                                                                key={addon}
                                                                variant="outline"
                                                            >
                                                                {addon}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                    </td>
                                    <td className="py-2">
                                        {booking.technician?.name ?? '—'}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {booking.van?.name ?? '—'}
                                    </td>
                                    <td className="py-2">
                                        <Badge
                                            variant={bookingStatusBadgeVariant(
                                                booking.status,
                                            )}
                                        >
                                            {
                                                BOOKING_STATUS_LABELS[
                                                    booking.status
                                                ]
                                            }
                                        </Badge>
                                    </td>
                                    <td className="py-2">
                                        {MOVABLE_STATUSES.includes(
                                            booking.status,
                                        ) && (
                                            <Can permission="bookings.manage">
                                                <div className="flex justify-end gap-2">
                                                    <MoveBookingDialog
                                                        booking={booking}
                                                    />
                                                    <CancelBookingButton
                                                        booking={booking}
                                                    />
                                                </div>
                                            </Can>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </CardContent>
        </Card>
    );
}

export default function DispatchIndex({
    filters,
    serviceZones,
    technicians,
    isScopedToOwn,
    shifts,
    bookings,
}: {
    filters: DispatchFilters;
    serviceZones: BookingLookup[];
    technicians: BookingLookup[];
    isScopedToOwn: boolean;
    shifts?: DispatchShift[];
    bookings?: DispatchBooking[];
}) {
    // Live technician/van status — a 30s poll re-fetches just the
    // shift/booking deferred props (Inertia v3 `usePoll`), not the whole
    // page, so two dispatchers looking at the board stay roughly in sync
    // without a manual refresh.
    usePoll(30000, { only: ['shifts', 'bookings'] });

    const applyFilter = (next: Partial<DispatchFilters>) => {
        router.get(
            DispatchBoardController.index().url,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Dispatch Board" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Dispatch board"
                    description="A live view over technician shifts and today's bookings — every move/cancel here goes through the same slot-computation engine the storefront booking flow uses."
                />

                <BookingsSubNav active="dispatch" />

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 pt-6">
                        {!isScopedToOwn && (
                            <div className="grid gap-2">
                                <Label htmlFor="filter-zone">Zone</Label>
                                <Select
                                    value={
                                        filters.service_zone_id
                                            ? String(filters.service_zone_id)
                                            : ALL_VALUE
                                    }
                                    onValueChange={(v) =>
                                        applyFilter({
                                            service_zone_id:
                                                v === ALL_VALUE
                                                    ? null
                                                    : Number(v),
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="filter-zone"
                                        className="w-48"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_VALUE}>
                                            All zones
                                        </SelectItem>
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
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="filter-date">Date</Label>
                            <Input
                                id="filter-date"
                                type="date"
                                className="w-40"
                                value={filters.date}
                                onChange={(e) =>
                                    applyFilter({ date: e.target.value })
                                }
                            />
                        </div>

                        {!isScopedToOwn && (
                            <div className="grid gap-2">
                                <Label htmlFor="filter-technician">
                                    Technician
                                </Label>
                                <Select
                                    value={
                                        filters.technician_id
                                            ? String(filters.technician_id)
                                            : ALL_VALUE
                                    }
                                    onValueChange={(v) =>
                                        applyFilter({
                                            technician_id:
                                                v === ALL_VALUE
                                                    ? null
                                                    : Number(v),
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="filter-technician"
                                        className="w-48"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_VALUE}>
                                            All technicians
                                        </SelectItem>
                                        {technicians.map((technician) => (
                                            <SelectItem
                                                key={technician.id}
                                                value={String(technician.id)}
                                            >
                                                {technician.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Deferred
                    data={['shifts', 'bookings']}
                    fallback={<BoardSkeleton />}
                >
                    <div className="space-y-6">
                        <ShiftsTable shifts={shifts ?? []} />
                        <BookingsTable bookings={bookings ?? []} />
                    </div>
                </Deferred>
            </div>
        </>
    );
}

DispatchIndex.layout = {
    breadcrumbs: [
        { title: 'Bookings', href: DispatchBoardController.index().url },
        {
            title: 'Dispatch board',
            href: DispatchBoardController.index().url,
        },
    ] satisfies BreadcrumbItem[],
};
