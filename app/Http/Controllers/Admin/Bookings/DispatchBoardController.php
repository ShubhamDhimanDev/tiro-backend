<?php

namespace App\Http\Controllers\Admin\Bookings;

use App\Enums\BookingStatus;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Bookings\DispatchMoveRequest;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Services\Bookings\BookingCancellationService;
use App\Services\Bookings\SlotComputationService;
use App\Support\Auth\AdminGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin dispatch/booking board — a UI over {@see TechnicianShift} +
 * {@see Booking}, filterable by zone/date/technician. Introduces no parallel
 * scheduling state: every write (reassign technician, move slot, cancel)
 * goes through the same tables and the same {@see SlotComputationService}
 * the customer-facing `App\Http\Controllers\Api\V1\Bookings\BookingController`
 * uses, so the two surfaces can never disagree about what's bookable — see
 * docs/architecture/04-booking-capacity-engine.md's "Dispatch board" note.
 *
 * `move()`/`cancel()` deliberately do **not** run
 * `CancellationPolicy::forZone()->feeFor()` the way the customer-facing
 * reschedule/cancel endpoints do — an operationally-driven staff change
 * (technician called in sick, zone re-balancing) is a different business
 * event from a customer-initiated late change, and there is currently no
 * schema support for a staff "this was the customer's request, apply the
 * fee anyway" override. Flagged back as an open product/design question
 * rather than guessed at; see the handback report.
 *
 * `move()`/`cancel()` each write one {@see AuditLog} row (`auditable_type
 * = Booking::class`), same standing pattern as `RoleAssignmentService` —
 * not a feature-specific audit table. This is what lets Phase 6 reporting
 * (and any future cancellation comms) distinguish a staff-initiated
 * dispatch-board change from a customer's own reschedule/cancel, which
 * `Booking.status` alone can't: a `cancelled` row looks identical either
 * way without this trail, and that distinction can't be backfilled once
 * real volume accumulates.
 */
class DispatchBoardController extends Controller
{
    private const LOCK_SECONDS = 10;

    private const SLOT_UNAVAILABLE_MESSAGE = 'This slot is no longer available, please choose another.';

    /**
     * Statuses a booking may still be moved/cancelled from — mirrors
     * `BookingController::RESCHEDULABLE_STATUSES` exactly (not extracted to
     * a shared constant since that file is backend-agent's; flagged as a
     * small follow-up rather than reaching into their controller).
     *
     * @var list<BookingStatus>
     */
    private const MOVABLE_STATUSES = [BookingStatus::PendingHold, BookingStatus::Confirmed];

    public function __construct(
        private readonly SlotComputationService $slots,
        private readonly BookingCancellationService $bookingCancellation,
    ) {}

    /**
     * Display the board for the given (or default) zone/date/technician
     * filters. Shift/booking rows are deferred props (Inertia v3) — the
     * initial payload is filter/lookup data only, so the page paints
     * immediately with a skeleton while the (potentially heavier)
     * shift/booking queries resolve.
     */
    public function index(Request $request): Response
    {
        $user = AdminGuard::user($request);
        $scopedTechnician = $this->scopedTechnicianFor($user);

        $serviceZones = ServiceZone::query()
            ->where('status', Status::Active)
            ->orderBy('name')
            ->get(['id', 'name']);

        $technicians = Technician::query()
            ->where('status', Status::Active)
            ->when($scopedTechnician, fn ($q) => $q->whereKey($scopedTechnician->id))
            ->orderBy('name')
            ->get(['id', 'name']);

        $date = $this->resolveDate($request, $scopedTechnician);
        $zoneId = $scopedTechnician !== null ? null : $this->nullableIntInput($request, 'service_zone_id');
        $technicianId = $scopedTechnician !== null ? $scopedTechnician->id : $this->nullableIntInput($request, 'technician_id');

        return Inertia::render('bookings/dispatch/index', [
            'filters' => [
                'service_zone_id' => $zoneId,
                'date' => $date->toDateString(),
                'technician_id' => $technicianId,
            ],
            'serviceZones' => $serviceZones,
            'technicians' => $technicians,
            // UX-only signal for whether to show the filter/action affordances
            // full board access unlocks — never the security boundary. The
            // frontend reads move/cancel permission the standard way
            // (`usePermissions()`/`<Can>` against the shared `auth` prop),
            // not from this flag; server routes independently enforce
            // `bookings.manage` regardless of what the UI shows.
            'isScopedToOwn' => $scopedTechnician !== null,
            'shifts' => Inertia::defer(fn () => $this->shiftsFor($zoneId, $date, $technicianId), 'board'),
            'bookings' => Inertia::defer(fn () => $this->bookingsFor($zoneId, $date, $technicianId), 'board'),
        ]);
    }

    /**
     * Live "who's actually free for this slot" preview for the move dialog
     * — calls the exact same {@see SlotComputationService} query the
     * customer-facing flow uses, never a second availability check. JSON,
     * not an Inertia response — a lightweight lookup the move dialog polls
     * as the admin edits date/time, not a page navigation.
     */
    public function availability(Request $request, Booking $booking): JsonResponse
    {
        abort_unless(AdminGuard::optionalUser($request)?->can('bookings.manage'), 403);

        $validated = $request->validate([
            'scheduled_date' => ['required', 'date'],
            'slot_start' => ['required', 'date_format:H:i'],
        ]);

        $zone = $booking->serviceZone;
        $date = CarbonImmutable::parse($validated['scheduled_date'])->startOfDay();
        $requiresAlignment = in_array('alignment', $booking->addons ?? [], true);

        $candidates = $this->slots->eligibleTechniciansForSlot(
            $zone, $date, $validated['slot_start'], $booking->duration_minutes, $requiresAlignment, excludeBookingId: $booking->id,
        );

        $technicianNames = Technician::query()
            ->whereIn('id', array_column($candidates, 'technician_id'))
            ->pluck('name', 'id');

        return response()->json([
            'data' => array_map(fn (array $candidate): array => [
                'technician_id' => $candidate['technician_id'],
                'technician_name' => $technicianNames->get($candidate['technician_id'], "Technician #{$candidate['technician_id']}"),
                'van_id' => $candidate['van_id'],
            ], $candidates),
        ]);
    }

    /**
     * Reschedule and/or reassign a booking — re-validated through the same
     * slot-computation engine and the same cache-lock re-check pattern as
     * `BookingController::reschedule()`, not a blind field update. Cart
     * contents/duration are not editable here, matching that endpoint.
     */
    public function move(DispatchMoveRequest $request, Booking $booking): RedirectResponse
    {
        $actor = AdminGuard::user($request);

        if (! in_array($booking->status, self::MOVABLE_STATUSES, true)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This booking can no longer be moved.')]);

            return back();
        }

        $zone = $booking->serviceZone;
        $requiresAlignment = in_array('alignment', $booking->addons ?? [], true);
        $durationMinutes = $booking->duration_minutes;

        $scheduledDate = CarbonImmutable::parse($request->validated('scheduled_date'))->startOfDay();
        $slotStart = $request->validated('slot_start');
        // Normalize "" (an unselected/"Auto" dropdown value) to null
        // alongside an outright-absent key — both mean "no preference,
        // first-fit".
        $preferredTechnicianId = $request->validated('technician_id');
        $preferredTechnicianId = $preferredTechnicianId === '' || $preferredTechnicianId === null ? null : (int) $preferredTechnicianId;

        $candidates = $this->slots->eligibleTechniciansForSlot(
            $zone, $scheduledDate, $slotStart, $durationMinutes, $requiresAlignment, excludeBookingId: $booking->id,
        );

        if ($preferredTechnicianId !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => $candidate['technician_id'] === $preferredTechnicianId,
            ));
        }

        foreach ($candidates as $candidate) {
            $lock = Cache::lock($this->lockKey($candidate['technician_id'], $scheduledDate, $slotStart), self::LOCK_SECONDS);

            if (! $lock->get()) {
                continue;
            }

            try {
                $stillFree = $this->slots->isTechnicianSlotFree(
                    $zone, $candidate['technician_id'], $scheduledDate, $slotStart, $durationMinutes, $requiresAlignment, excludeBookingId: $booking->id,
                );

                if (! $stillFree) {
                    continue;
                }

                DB::transaction(function () use ($booking, $scheduledDate, $slotStart, $durationMinutes, $candidate, $actor): void {
                    $before = [
                        'scheduled_date' => $booking->scheduled_date->toDateString(),
                        'slot_start' => $booking->slot_start,
                        'slot_end' => $booking->slot_end,
                        'technician_id' => $booking->technician_id,
                        'van_id' => $booking->van_id,
                    ];

                    $booking->forceFill([
                        'scheduled_date' => $scheduledDate->toDateString(),
                        'slot_start' => $slotStart,
                        'slot_end' => $this->slots->slotEndTime($slotStart, $durationMinutes),
                        'technician_id' => $candidate['technician_id'],
                        'van_id' => $candidate['van_id'],
                    ])->save();

                    AuditLog::create([
                        'auditable_type' => Booking::class,
                        'auditable_id' => $booking->id,
                        'action' => 'bookings.moved',
                        'actor_id' => $actor->id,
                        'before' => $before,
                        'after' => [
                            'scheduled_date' => $booking->scheduled_date->toDateString(),
                            'slot_start' => $booking->slot_start,
                            'slot_end' => $booking->slot_end,
                            'technician_id' => $booking->technician_id,
                            'van_id' => $booking->van_id,
                        ],
                    ]);
                });

                Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking moved.')]);

                return back();
            } finally {
                $lock->release();
            }
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => self::SLOT_UNAVAILABLE_MESSAGE]);

        return back();
    }

    /**
     * Cancel a booking from the dispatch board. Sets `status = cancelled`
     * immediately, same as the customer-facing cancel endpoint — see the
     * class docblock for why no fee is assessed here. The actual write is
     * {@see BookingCancellationService::cancel()} — this method only owns
     * the eligibility check and the toast, so the Orders admin's
     * order-cancel action can reuse the exact same booking-release
     * mechanism without duplicating it.
     */
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $actor = AdminGuard::optionalUser($request);

        abort_unless($actor?->can('bookings.manage'), 403);

        if (! in_array($booking->status, self::MOVABLE_STATUSES, true)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This booking can no longer be cancelled.')]);

            return back();
        }

        $this->bookingCancellation->cancel($booking, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking cancelled.')]);

        return back();
    }

    /**
     * Resolve the acting user's `Technician` scope: null for anyone holding
     * `bookings.manage` (sees the full board), otherwise their own
     * `Technician` row for the `bookings.view-own` tier — see
     * {@see BookingPolicy}.
     */
    private function scopedTechnicianFor(User $user): ?Technician
    {
        if ($user->can('bookings.manage')) {
            return null;
        }

        return $user->technician;
    }

    /**
     * Resolve the requested date, clamped to
     * `[today, today + technician_view_window_days]` for a
     * `bookings.view-own`-scoped technician — the same window
     * {@see BookingPolicy} enforces at the row level, applied
     * here so the board's own date picker can't request a day the policy
     * would deny anyway.
     */
    private function resolveDate(Request $request, ?Technician $scopedTechnician): CarbonImmutable
    {
        $requested = $request->query('date');
        $date = is_string($requested) && $requested !== '' ? CarbonImmutable::parse($requested)->startOfDay() : CarbonImmutable::now()->startOfDay();

        if ($scopedTechnician === null) {
            return $date;
        }

        $today = CarbonImmutable::now()->startOfDay();
        $windowEnd = $today->addDays((int) config('bookings.technician_view_window_days', 14));

        if ($date->lessThan($today)) {
            return $today;
        }

        if ($date->greaterThan($windowEnd)) {
            return $windowEnd;
        }

        return $date;
    }

    private function nullableIntInput(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return Collection<int, TechnicianShift>
     */
    private function shiftsFor(?int $zoneId, CarbonImmutable $date, ?int $technicianId): Collection
    {
        return TechnicianShift::query()
            ->with(['technician:id,name,status', 'van:id,name,rego,has_alignment_equipment', 'serviceZone:id,name'])
            ->where('date', $date->toDateString())
            ->where('status', Status::Active)
            ->when($zoneId !== null, fn ($q) => $q->where('service_zone_id', $zoneId))
            ->when($technicianId !== null, fn ($q) => $q->where('technician_id', $technicianId))
            ->orderBy('shift_start')
            ->get();
    }

    /**
     * @return Collection<int, Booking>
     */
    private function bookingsFor(?int $zoneId, CarbonImmutable $date, ?int $technicianId): Collection
    {
        return Booking::query()
            ->with([
                'technician:id,name',
                'van:id,name,rego',
                'serviceZone:id,name',
                'customer:id,name,mobile',
                'lineItems.tyreVariant:id,sku,width,profile,rim_diameter',
            ])
            ->where('scheduled_date', $date->toDateString())
            ->when($zoneId !== null, fn ($q) => $q->where('service_zone_id', $zoneId))
            ->when($technicianId !== null, fn ($q) => $q->where('technician_id', $technicianId))
            ->orderBy('slot_start')
            ->get();
    }

    private function lockKey(int $technicianId, CarbonImmutable $scheduledDate, string $slotStart): string
    {
        return "booking-slot:{$technicianId}:{$scheduledDate->toDateString()}:{$slotStart}";
    }
}
