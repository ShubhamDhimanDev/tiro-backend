<?php

namespace App\Http\Controllers\Admin\Locations;

use App\Enums\ServiceZoneType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Locations\SuburbRequest;
use App\Models\ServiceZone;
use App\Models\State;
use App\Models\Suburb;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SuburbController extends Controller
{
    /**
     * Display every suburb, with its current suburb_list-zone memberships
     * and the lookups (states, suburb_list zones) the create/edit form and
     * membership editor need.
     */
    public function index(): Response
    {
        $suburbs = Suburb::query()
            ->with([
                'state:id,code,name',
                'serviceZones:service_zones.id,service_zones.name,service_zones.status,service_zones.state_id',
            ])
            ->orderBy('name')
            ->get();

        $states = State::query()->orderBy('name')->get(['id', 'code', 'name', 'is_active']);

        $suburbListZones = ServiceZone::query()
            ->where('type', ServiceZoneType::SuburbList)
            ->orderBy('name')
            ->get(['id', 'name', 'state_id', 'status']);

        return Inertia::render('locations/suburbs/index', [
            'suburbs' => $suburbs,
            'states' => $states,
            'suburbListZones' => $suburbListZones,
        ]);
    }

    /**
     * Store a newly created suburb.
     */
    public function store(SuburbRequest $request): RedirectResponse
    {
        Suburb::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Suburb created.')]);

        return back();
    }

    /**
     * Update the given suburb.
     */
    public function update(SuburbRequest $request, Suburb $suburb): RedirectResponse
    {
        $suburb->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Suburb updated.')]);

        return back();
    }

    /**
     * Delete the given suburb. Cascades to its zone-membership pivot rows
     * at the DB level (`cascadeOnDelete`).
     */
    public function destroy(Suburb $suburb): RedirectResponse
    {
        $suburb->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Suburb deleted.')]);

        return back();
    }
}
