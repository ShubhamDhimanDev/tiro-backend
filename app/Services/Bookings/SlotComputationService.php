<?php

namespace App\Services\Bookings;

use App\Enums\BookingStatus;
use App\Enums\Status;
use App\Models\Booking;
use App\Models\ServiceZone;
use App\Models\TechnicianShift;
use App\Models\Van;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Implements the exact query sequence/algorithm from
 * docs/architecture/04-booking-capacity-engine.md's "Slot computation"
 * section (steps 1-7). Server-computed only — the frontend never computes
 * this itself.
 *
 * @phpstan-type ShiftFreeWindow array{technician_id: int, van_id: int, windows: list<array{0: int, 1: int}>}
 */
class SlotComputationService
{
    /**
     * Fixed travel buffer between jobs — not true routing/ETA, an explicit
     * placeholder per the architecture doc (a real routing engine is
     * post-MVP).
     */
    private const TRAVEL_BUFFER_MINUTES = 20;

    /** Candidate slot start times are sliced on this fixed grid, in minutes. */
    private const SLOT_GRID_MINUTES = 15;

    /**
     * Step 5-7: the deduplicated, unioned candidate slot list for one date —
     * what `GET /api/v1/booking-slots` returns per day. The customer picks a
     * time, never a technician.
     *
     * @return list<array{start: string, end: string}>
     */
    public function candidateSlotsForDate(ServiceZone $zone, CarbonImmutable $date, int $durationMinutes, bool $requiresAlignment): array
    {
        $operatingWindow = $this->operatingWindowFor($zone, $date);

        if ($operatingWindow === null) {
            return [];
        }

        $startMinutes = [];

        foreach ($this->eligibleShiftFreeWindows($zone, $date, $requiresAlignment) as $shift) {
            foreach ($shift['windows'] as $window) {
                foreach ($this->sliceGrid($window, $durationMinutes, $operatingWindow) as $slotStart) {
                    $startMinutes[$slotStart] = $slotStart;
                }
            }
        }

        ksort($startMinutes);

        return array_values(array_map(fn (int $start): array => [
            'start' => $this->minutesToTime($start),
            'end' => $this->minutesToTime($start + $durationMinutes),
        ], $startMinutes));
    }

    /**
     * The zone's operating window for a date as `["start" => "08:00",
     * "end" => "17:00"]`, or null when closed — the window a flexible
     * booking promises the customer.
     *
     * @return array{start: string, end: string}|null
     */
    public function operatingWindowStrings(ServiceZone $zone, CarbonImmutable $date): ?array
    {
        $window = $this->operatingWindowFor($zone, $date);

        if ($window === null) {
            return null;
        }

        return ['start' => $this->minutesToTime($window[0]), 'end' => $this->minutesToTime($window[1])];
    }

    /**
     * Eligible technician/van candidates for one specific requested
     * `slot_start`, ordered lowest `technician_id` first (the documented
     * first-fit tie-break) — used at booking-creation/reschedule time to
     * pick who to try to assign, before the per-candidate lock re-check.
     *
     * @return list<array{technician_id: int, van_id: int}>
     */
    public function eligibleTechniciansForSlot(ServiceZone $zone, CarbonImmutable $date, string $slotStart, int $durationMinutes, bool $requiresAlignment, ?int $excludeBookingId = null): array
    {
        $slotStartMinutes = $this->timeToMinutes($slotStart);
        $slotEndMinutes = $slotStartMinutes + $durationMinutes;

        $operatingWindow = $this->operatingWindowFor($zone, $date);

        if ($operatingWindow === null || $slotStartMinutes < $operatingWindow[0] || $slotEndMinutes > $operatingWindow[1]) {
            return [];
        }

        $candidates = $this->eligibleShiftFreeWindows($zone, $date, $requiresAlignment, $excludeBookingId)
            ->filter(fn (array $shift): bool => $this->windowsContain($shift['windows'], $slotStartMinutes, $slotEndMinutes))
            ->sortBy('technician_id')
            ->map(fn (array $shift): array => ['technician_id' => $shift['technician_id'], 'van_id' => $shift['van_id']])
            ->all();

        return array_values($candidates);
    }

    /**
     * Re-check, inside the per-technician hold lock, that a specific
     * technician's window still covers the requested slot — a fresh query,
     * not a reuse of any earlier read, since the whole point of the lock is
     * to protect against another request having written a competing booking
     * in between.
     *
     * `$excludeBookingId`: when re-checking for a *reschedule* of an
     * existing booking, that booking's own current row must not count as
     * "occupying" its technician/van — otherwise a booking could never be
     * reschedulable onto a slot that overlaps (or is identical to) the one
     * it currently holds.
     */
    public function isTechnicianSlotFree(ServiceZone $zone, int $technicianId, CarbonImmutable $date, string $slotStart, int $durationMinutes, bool $requiresAlignment, ?int $excludeBookingId = null): bool
    {
        $slotStartMinutes = $this->timeToMinutes($slotStart);
        $slotEndMinutes = $slotStartMinutes + $durationMinutes;

        return $this->eligibleShiftFreeWindows($zone, $date, $requiresAlignment, $excludeBookingId)
            ->where('technician_id', $technicianId)
            ->contains(fn (array $shift): bool => $this->windowsContain($shift['windows'], $slotStartMinutes, $slotEndMinutes));
    }

    /**
     * Compute a slot's end time (`"H:i"`) from its start and the booking's
     * duration — shared by the controller so it never re-derives this
     * arithmetic independently of the private minute-math helpers below.
     */
    public function slotEndTime(string $slotStart, int $durationMinutes): string
    {
        return $this->minutesToTime($this->timeToMinutes($slotStart) + $durationMinutes);
    }

    /**
     * Steps 1-4: fetch active shifts for the zone/date (filtered to
     * alignment-equipped vans when required), fetch same-day occupied
     * bookings for the technicians those shifts cover, exclude any van
     * already at its daily job cap, and reduce each remaining shift to its
     * free-minute windows after subtracting occupied bookings (with travel
     * buffer).
     *
     * @return Collection<int, ShiftFreeWindow>
     */
    private function eligibleShiftFreeWindows(ServiceZone $zone, CarbonImmutable $date, bool $requiresAlignment, ?int $excludeBookingId = null): Collection
    {
        $dateString = $date->toDateString();

        // Step 1: hits the (service_zone_id, date) composite index.
        $shifts = TechnicianShift::query()
            ->where('service_zone_id', $zone->id)
            ->where('date', $dateString)
            ->where('status', Status::Active)
            ->when(
                $requiresAlignment,
                fn (Builder $query) => $query->whereHas('van', fn (Builder $vanQuery) => $vanQuery->where('has_alignment_equipment', true)),
            )
            ->get();

        if ($shifts->isEmpty()) {
            return collect();
        }

        $technicianIds = $shifts->pluck('technician_id')->unique()->values();
        $vanIds = $shifts->pluck('van_id')->unique()->values();

        // Step 2: hits the (technician_id, scheduled_date) index. Expected
        // row count is small per the architecture doc, so grouping in PHP
        // (below) rather than a second aggregate query is intentional.
        $occupyingStatuses = array_map(fn (BookingStatus $status) => $status->value, BookingStatus::occupying());

        $bookingsByTechnician = Booking::query()
            ->whereIn('technician_id', $technicianIds)
            ->where('scheduled_date', $dateString)
            ->whereIn('status', $occupyingStatuses)
            ->when($excludeBookingId !== null, fn (Builder $query) => $query->where('id', '!=', $excludeBookingId))
            ->orderBy('slot_start')
            ->get()
            ->groupBy('technician_id');

        // Step 4: per-van daily job cap, independent of remaining open time.
        $vanJobCounts = Booking::query()
            ->whereIn('van_id', $vanIds)
            ->where('scheduled_date', $dateString)
            ->whereIn('status', $occupyingStatuses)
            ->when($excludeBookingId !== null, fn (Builder $query) => $query->where('id', '!=', $excludeBookingId))
            ->selectRaw('van_id, COUNT(*) as job_count')
            ->groupBy('van_id')
            ->pluck('job_count', 'van_id');

        $vansById = Van::query()->whereIn('id', $vanIds)->get()->keyBy('id');

        return $shifts
            ->filter(function (TechnicianShift $shift) use ($vanJobCounts, $vansById): bool {
                $van = $vansById->get($shift->van_id);

                if ($van === null) {
                    return false;
                }

                $jobCount = (int) ($vanJobCounts[$shift->van_id] ?? 0);

                return $jobCount < $van->max_jobs_per_day;
            })
            ->map(function (TechnicianShift $shift) use ($bookingsByTechnician): array {
                /** @var Collection<int, Booking> $technicianBookings */
                $technicianBookings = $bookingsByTechnician->get($shift->technician_id, collect());

                return [
                    'technician_id' => $shift->technician_id,
                    'van_id' => $shift->van_id,
                    'windows' => $this->subtractOccupiedWindows(
                        $this->timeToMinutes($shift->shift_start),
                        $this->timeToMinutes($shift->shift_end),
                        $technicianBookings,
                    ),
                ];
            })
            ->values();
    }

    /**
     * Step 3: subtract each booking's `[slot_start - buffer, slot_end +
     * buffer]` from the shift's open interval, returning what's left as a
     * list of disjoint `[start, end]` minute windows.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return list<array{0: int, 1: int}>
     */
    private function subtractOccupiedWindows(int $shiftStart, int $shiftEnd, Collection $bookings): array
    {
        $windows = [[$shiftStart, $shiftEnd]];

        foreach ($bookings as $booking) {
            $occupiedStart = $this->timeToMinutes($booking->slot_start) - self::TRAVEL_BUFFER_MINUTES;
            $occupiedEnd = $this->timeToMinutes($booking->slot_end) + self::TRAVEL_BUFFER_MINUTES;

            $next = [];

            foreach ($windows as [$windowStart, $windowEnd]) {
                if ($occupiedEnd <= $windowStart || $occupiedStart >= $windowEnd) {
                    $next[] = [$windowStart, $windowEnd];

                    continue;
                }

                if ($occupiedStart > $windowStart) {
                    $next[] = [$windowStart, min($occupiedStart, $windowEnd)];
                }

                if ($occupiedEnd < $windowEnd) {
                    $next[] = [max($occupiedEnd, $windowStart), $windowEnd];
                }
            }

            $windows = $next;
        }

        return $windows;
    }

    /**
     * Step 5: slice one free window into grid-aligned slot start times where
     * `[start, start + duration]` fits entirely inside both the free window
     * and the zone's operating hours for that weekday.
     *
     * @param  array{0: int, 1: int}  $window
     * @param  array{0: int, 1: int}  $operatingWindow
     * @return list<int>
     */
    private function sliceGrid(array $window, int $durationMinutes, array $operatingWindow): array
    {
        $lowerBound = max($window[0], $operatingWindow[0]);
        $upperBound = min($window[1], $operatingWindow[1]);

        $grid = self::SLOT_GRID_MINUTES;
        $firstSlot = (int) (ceil($lowerBound / $grid) * $grid);

        $slots = [];

        for ($start = $firstSlot; $start + $durationMinutes <= $upperBound; $start += $grid) {
            $slots[] = $start;
        }

        return $slots;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $windows
     */
    private function windowsContain(array $windows, int $slotStartMinutes, int $slotEndMinutes): bool
    {
        foreach ($windows as [$windowStart, $windowEnd]) {
            if ($slotStartMinutes >= $windowStart && $slotEndMinutes <= $windowEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve `[openMinutes, closeMinutes]` for the zone on this date's
     * weekday, or null if the zone is closed that day —
     * `ServiceZone.operating_hours` shape is locked in
     * docs/architecture/01-data-model.md.
     *
     * @return array{0: int, 1: int}|null
     */
    private function operatingWindowFor(ServiceZone $zone, CarbonImmutable $date): ?array
    {
        $dayKey = strtolower($date->format('D'));
        $hours = $zone->operating_hours[$dayKey] ?? null;

        if ($hours === null) {
            return null;
        }

        return [$this->timeToMinutes($hours['open']), $this->timeToMinutes($hours['close'])];
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    private function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
