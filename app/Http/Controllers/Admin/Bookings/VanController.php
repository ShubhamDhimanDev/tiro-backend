<?php

namespace App\Http\Controllers\Admin\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Bookings\VanRequest;
use App\Models\StockLocation;
use App\Models\TechnicianShift;
use App\Models\Van;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roster CRUD for {@see Van} — the daily job-cap unit the booking engine
 * schedules against (docs/architecture/04-booking-capacity-engine.md's
 * "Slot computation" step 4). No `destroy` route: a van is retired via
 * `status = inactive`, not deleted, preserving its shift/booking history
 * for reporting — same posture as {@see TechnicianShift}.
 */
class VanController extends Controller
{
    /**
     * Display every van, plus the stock-location lookup the form needs.
     */
    public function index(): Response
    {
        $vans = Van::query()
            ->with('homeStockLocation:id,name')
            ->orderBy('name')
            ->get();

        $stockLocations = StockLocation::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('bookings/vans/index', [
            'vans' => $vans,
            'stockLocations' => $stockLocations,
        ]);
    }

    /**
     * Store a newly created van.
     */
    public function store(VanRequest $request): RedirectResponse
    {
        Van::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Van created.')]);

        return back();
    }

    /**
     * Update the given van.
     */
    public function update(VanRequest $request, Van $van): RedirectResponse
    {
        $van->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Van updated.')]);

        return back();
    }
}
