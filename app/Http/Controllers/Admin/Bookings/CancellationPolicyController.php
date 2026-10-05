<?php

namespace App\Http\Controllers\Admin\Bookings;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Bookings\CancellationPolicyRequest;
use App\Models\CancellationPolicy;
use App\Models\ServiceZone;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for {@see CancellationPolicy} — zone-specific rows plus the one
 * global default (`service_zone_id = null`), evaluated by
 * `CancellationPolicy::forZone()` on every reschedule/cancel (both the
 * customer-facing API and the dispatch board's move/cancel actions — see
 * {@see DispatchBoardController}). The seeded global default is
 * intentionally permissive (`notice_hours = 0`, `fee_amount = 0`); real
 * numbers are a pending business decision (open decision #2) — this screen
 * just lets ops edit them without a deploy once confirmed.
 */
class CancellationPolicyController extends Controller
{
    /**
     * Display every cancellation policy, plus the zone lookup the form
     * needs.
     */
    public function index(): Response
    {
        $policies = CancellationPolicy::query()
            ->with('serviceZone:id,name')
            ->orderByRaw('service_zone_id is not null')
            ->orderBy('service_zone_id')
            ->get();

        $serviceZones = ServiceZone::query()
            ->where('status', Status::Active)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('bookings/cancellation-policies/index', [
            'policies' => $policies,
            'serviceZones' => $serviceZones,
        ]);
    }

    /**
     * Store a newly created cancellation policy.
     */
    public function store(CancellationPolicyRequest $request): RedirectResponse
    {
        CancellationPolicy::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cancellation policy created.')]);

        return back();
    }

    /**
     * Update the given cancellation policy.
     */
    public function update(CancellationPolicyRequest $request, CancellationPolicy $cancellationPolicy): RedirectResponse
    {
        $cancellationPolicy->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cancellation policy updated.')]);

        return back();
    }

    /**
     * Delete the given cancellation policy. The global default row
     * (`service_zone_id = null`) is protected — `CancellationPolicy::forZone()`
     * has no further fallback once it's gone, which would silently stop
     * every reschedule/cancel from ever charging (or even evaluating) a fee.
     * Deleting a zone-specific row is a legitimate "revert to global
     * default" action and isn't restricted the same way.
     */
    public function destroy(CancellationPolicy $cancellationPolicy): RedirectResponse
    {
        if ($cancellationPolicy->service_zone_id === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The global default policy cannot be deleted — edit it instead.')]);

            return back();
        }

        $cancellationPolicy->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cancellation policy deleted.')]);

        return back();
    }
}
