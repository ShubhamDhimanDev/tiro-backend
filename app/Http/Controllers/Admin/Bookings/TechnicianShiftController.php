<?php

namespace App\Http\Controllers\Admin\Bookings;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Bookings\TechnicianShiftRequest;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\Van;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roster CRUD for {@see TechnicianShift} — the capacity source of truth the
 * dispatch board and the booking engine both read (see
 * docs/architecture/04-booking-capacity-engine.md). No `destroy` route: a
 * shift is toggled `status = inactive` for a sick-day/roster change rather
 * than deleted, preserving history for reporting — see
 * docs/architecture/01-data-model.md's `TechnicianShift` section.
 */
class TechnicianShiftController extends Controller
{
    /**
     * Shifts from 7 days in the past (for recent-history visibility) through
     * 60 days ahead — a roster screen, not an unbounded historical archive.
     * Reporting (a later phase) is the right surface for older history.
     */
    private const PAST_DAYS = 7;

    private const FUTURE_DAYS = 60;

    /**
     * Display shifts in the roster window, plus the lookups (technicians,
     * vans, zones) the create/edit form needs.
     */
    public function index(): Response
    {
        $shifts = TechnicianShift::query()
            ->with(['technician:id,name,status', 'van:id,name,rego', 'serviceZone:id,name'])
            ->whereBetween('date', [now()->subDays(self::PAST_DAYS)->toDateString(), now()->addDays(self::FUTURE_DAYS)->toDateString()])
            ->orderBy('date')
            ->orderBy('shift_start')
            ->get();

        $technicians = Technician::query()
            ->where('status', Status::Active)
            ->orderBy('name')
            ->get(['id', 'name']);

        $vans = Van::query()
            ->where('status', Status::Active)
            ->orderBy('name')
            ->get(['id', 'name', 'rego']);

        $serviceZones = ServiceZone::query()
            ->where('status', Status::Active)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('bookings/shifts/index', [
            'shifts' => $shifts,
            'technicians' => $technicians,
            'vans' => $vans,
            'serviceZones' => $serviceZones,
        ]);
    }

    /**
     * Store a newly created shift.
     */
    public function store(TechnicianShiftRequest $request): RedirectResponse
    {
        TechnicianShift::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shift created.')]);

        return back();
    }

    /**
     * Update the given shift.
     */
    public function update(TechnicianShiftRequest $request, TechnicianShift $technicianShift): RedirectResponse
    {
        $technicianShift->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shift updated.')]);

        return back();
    }
}
