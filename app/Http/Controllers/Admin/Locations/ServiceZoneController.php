<?php

namespace App\Http\Controllers\Admin\Locations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Locations\ServiceZoneRequest;
use App\Models\ServiceZone;
use App\Models\State;
use App\Models\Suburb;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServiceZoneController extends Controller
{
    /**
     * Display every service zone, plus the lookups (states, suburbs) the
     * create/edit form and the suburb_list membership editor need.
     *
     * Each zone's own `suburbs` are included so the frontend can compute
     * "this suburb is already in another active zone" overlap warnings
     * without a dedicated endpoint — see docs/architecture/02-api-contract.md
     * on zone-resolution not treating overlap as an error.
     */
    public function index(): Response
    {
        $serviceZones = ServiceZone::query()
            ->with([
                'state:id,code,name',
                'suburbs:suburbs.id,suburbs.name,suburbs.postcode,suburbs.state_id',
            ])
            ->withCount('stockLocations')
            ->orderBy('state_id')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        $states = State::query()->orderBy('name')->get(['id', 'code', 'name', 'is_active']);

        $suburbs = Suburb::query()->orderBy('name')->get(['id', 'name', 'state_id', 'postcode']);

        return Inertia::render('locations/zones/index', [
            'serviceZones' => $serviceZones,
            'states' => $states,
            'suburbs' => $suburbs,
        ]);
    }

    /**
     * Store a newly created service zone.
     */
    public function store(ServiceZoneRequest $request): RedirectResponse
    {
        ServiceZone::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service zone created.')]);

        return back();
    }

    /**
     * Update the given service zone.
     */
    public function update(ServiceZoneRequest $request, ServiceZone $serviceZone): RedirectResponse
    {
        $serviceZone->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service zone updated.')]);

        return back();
    }
}
