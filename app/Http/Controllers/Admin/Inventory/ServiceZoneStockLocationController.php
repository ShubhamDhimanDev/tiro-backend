<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Models\ServiceZone;
use App\Models\ServiceZoneStockLocation;
use App\Models\StockLocation;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Manages the {@see ServiceZoneStockLocation} pivot — which
 * service zones a stock location's inventory backs. Attach/detach only; no
 * index of its own (surfaced inline on the stock-location list/detail
 * pages).
 */
class ServiceZoneStockLocationController
{
    /**
     * Link the given stock location to a service zone.
     */
    public function store(Request $request, StockLocation $stockLocation): RedirectResponse
    {
        abort_unless(AdminGuard::optionalUser($request)?->can('inventory.manage'), 403);

        $validated = $request->validate([
            'service_zone_id' => ['required', Rule::exists('service_zones', 'id')],
        ]);

        $stockLocation->serviceZones()->syncWithoutDetaching([$validated['service_zone_id']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Zone linked.')]);

        return back();
    }

    /**
     * Unlink the given stock location from a service zone.
     */
    public function destroy(Request $request, StockLocation $stockLocation, ServiceZone $serviceZone): RedirectResponse
    {
        abort_unless(AdminGuard::optionalUser($request)?->can('inventory.manage'), 403);

        $stockLocation->serviceZones()->detach($serviceZone->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Zone unlinked.')]);

        return back();
    }
}
