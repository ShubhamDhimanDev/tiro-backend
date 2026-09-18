<?php

namespace App\Http\Controllers\Admin\Locations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Locations\StateRequest;
use App\Models\State;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StateController extends Controller
{
    /**
     * Display every state/territory — active and inactive alike, so an
     * admin can manage the full set, not just today's launched geography.
     */
    public function index(): Response
    {
        $states = State::query()
            ->withCount(['serviceZones', 'suburbs'])
            ->orderBy('name')
            ->get();

        return Inertia::render('locations/states/index', [
            'states' => $states,
        ]);
    }

    /**
     * Store a newly created state.
     */
    public function store(StateRequest $request): RedirectResponse
    {
        State::create([
            ...$request->validated(),
            'is_active' => false,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('State created. Activate it separately once its zones are ready.')]);

        return back();
    }

    /**
     * Update the given state's code/name/status (not `is_active` — see
     * {@see self::toggleActive()}).
     */
    public function update(StateRequest $request, State $state): RedirectResponse
    {
        $state->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('State updated.')]);

        return back();
    }

    /**
     * Explicitly activate/deactivate a state — the actual lever for
     * expanding (or pausing) geography. Kept as its own confirmed action
     * rather than a field on the general edit form so it's never flipped
     * as a side effect of an unrelated name/code fix.
     */
    public function toggleActive(Request $request, State $state): RedirectResponse
    {
        abort_unless(AdminGuard::optionalUser($request)?->can('locations.manage'), 403);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $state->update(['is_active' => $validated['is_active']]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['is_active']
                ? __(':state is now active.', ['state' => $state->name])
                : __(':state is now inactive.', ['state' => $state->name]),
        ]);

        return back();
    }
}
