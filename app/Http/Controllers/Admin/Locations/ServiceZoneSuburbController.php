<?php

namespace App\Http\Controllers\Admin\Locations;

use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\ServiceZoneSuburb;
use App\Models\Suburb;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Manages the {@see ServiceZoneSuburb} pivot — which suburbs a
 * `suburb_list`-type zone covers. Attach/detach only; both the zone-edit
 * dialog and the suburb-edit dialog call these two endpoints.
 *
 * A suburb legitimately belonging to more than one active zone is a normal
 * (if surprising) outcome per the zone-resolution rule (suburb_list-match-
 * first, then nearest-radius, then `priority` tie-break) — so `store()`
 * warns rather than blocks when that happens.
 */
class ServiceZoneSuburbController
{
    /**
     * Assign a suburb to the given service zone.
     */
    public function store(Request $request, ServiceZone $serviceZone): RedirectResponse
    {
        abort_unless(AdminGuard::optionalUser($request)?->can('locations.manage'), 403);

        $validated = $request->validate([
            'suburb_id' => ['required', Rule::exists('suburbs', 'id')],
        ]);

        /** @var Suburb $suburb */
        $suburb = Suburb::query()->findOrFail($validated['suburb_id']);

        $overlappingZoneNames = $suburb->serviceZones()
            ->where('service_zones.id', '!=', $serviceZone->id)
            ->where('service_zones.status', Status::Active->value)
            ->pluck('service_zones.name');

        $serviceZone->suburbs()->syncWithoutDetaching([$suburb->id]);

        if ($overlappingZoneNames->isNotEmpty()) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __(':suburb added, but is also already in another active zone: :zones. Overlap resolution falls back to nearest-radius, then zone priority — this is expected, not an error.', [
                    'suburb' => $suburb->name,
                    'zones' => $overlappingZoneNames->implode(', '),
                ]),
            ]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __(':suburb added to :zone.', ['suburb' => $suburb->name, 'zone' => $serviceZone->name])]);
        }

        return back();
    }

    /**
     * Remove a suburb from the given service zone.
     */
    public function destroy(Request $request, ServiceZone $serviceZone, Suburb $suburb): RedirectResponse
    {
        abort_unless(AdminGuard::optionalUser($request)?->can('locations.manage'), 403);

        $serviceZone->suburbs()->detach($suburb->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':suburb removed from :zone.', ['suburb' => $suburb->name, 'zone' => $serviceZone->name])]);

        return back();
    }
}
