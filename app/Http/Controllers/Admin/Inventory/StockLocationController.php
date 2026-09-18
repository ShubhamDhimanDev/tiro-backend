<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Inventory\StockLocationRequest;
use App\Models\ServiceZone;
use App\Models\ServiceZoneStockLocation;
use App\Models\StockLocation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StockLocationController extends Controller
{
    /**
     * Display every stock location, with the active service zones each one
     * can be linked to via {@see ServiceZoneStockLocation}.
     */
    public function index(): Response
    {
        $stockLocations = StockLocation::query()
            ->withCount('inventoryItems')
            ->with(['serviceZones' => fn ($query) => $query->select('service_zones.id', 'service_zones.name', 'service_zones.status')])
            ->orderBy('name')
            ->get();

        $serviceZones = ServiceZone::query()
            ->with('state:id,code,name')
            ->where('status', Status::Active)
            ->orderBy('name')
            ->get(['id', 'name', 'state_id']);

        return Inertia::render('inventory/locations/index', [
            'stockLocations' => $stockLocations,
            'serviceZones' => $serviceZones,
        ]);
    }

    /**
     * Store a newly created stock location.
     */
    public function store(StockLocationRequest $request): RedirectResponse
    {
        StockLocation::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock location created.')]);

        return back();
    }

    /**
     * Update the given stock location.
     */
    public function update(StockLocationRequest $request, StockLocation $stockLocation): RedirectResponse
    {
        $stockLocation->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock location updated.')]);

        return back();
    }

    /**
     * Delete the given stock location. Cascades to its inventory rows and
     * zone links at the DB level (`cascadeOnDelete`) — the confirmation
     * dialog on the frontend is the only guard against an accidental delete.
     */
    public function destroy(StockLocation $stockLocation): RedirectResponse
    {
        $stockLocation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock location deleted.')]);

        return back();
    }
}
