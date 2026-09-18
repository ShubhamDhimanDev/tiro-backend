<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Enums\Status;
use App\Enums\VehicleFitmentSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Vehicles\VehicleRequest;
use App\Models\Vehicle;
use App\Models\VehicleFitment;
use App\Services\Vehicles\FitmentSetValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Row-level CRUD for {@see Vehicle} plus its nested {@see VehicleFitment}
 * rows — see docs/architecture/01-data-model.md's "Vehicles & fitment"
 * section and docs/architecture/07-admin-auth-permissions.md §3.1 for the
 * `vehicles` module RBAC decision. This is the row-level-correction path;
 * bulk loads go through {@see VehicleFitmentImportController} instead, both
 * ultimately writing through the same {@see FitmentSetValidator}
 * invariant.
 */
class VehicleController extends Controller
{
    /**
     * Display every vehicle with its full fitment row set.
     */
    public function index(): Response
    {
        $vehicles = Vehicle::query()
            ->with('fitments')
            ->orderBy('make')
            ->orderBy('model')
            ->orderByDesc('year_from')
            ->get();

        return Inertia::render('vehicles/index', [
            'vehicles' => $vehicles,
        ]);
    }

    /**
     * Store a newly created vehicle and its fitment row set.
     */
    public function store(VehicleRequest $request): RedirectResponse
    {
        [$attributes, $fitmentRows, $isStaggered] = $this->splitValidated($request);

        DB::transaction(function () use ($attributes, $fitmentRows, $isStaggered): void {
            $vehicle = Vehicle::create($attributes);
            $this->replaceFitments($vehicle, $fitmentRows, $isStaggered);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vehicle created.')]);

        return back();
    }

    /**
     * Update the given vehicle and replace its fitment row set.
     */
    public function update(VehicleRequest $request, Vehicle $vehicle): RedirectResponse
    {
        [$attributes, $fitmentRows, $isStaggered] = $this->splitValidated($request);

        DB::transaction(function () use ($vehicle, $attributes, $fitmentRows, $isStaggered): void {
            $vehicle->update($attributes);
            $this->replaceFitments($vehicle, $fitmentRows, $isStaggered);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vehicle updated.')]);

        return back();
    }

    /**
     * Delete the given vehicle. Cascades to its fitment rows at the DB level
     * (`cascadeOnDelete`).
     */
    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        $vehicle->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vehicle deleted.')]);

        return back();
    }

    /**
     * Split `VehicleRequest::validated()` into the plain `Vehicle` column
     * attributes and the fitment-row set, since `is_staggered`/`fitments`
     * are submitted together but neither is itself a `Vehicle` column.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: bool}
     */
    private function splitValidated(VehicleRequest $request): array
    {
        $validated = $request->validated();
        $isStaggered = (bool) $validated['is_staggered'];
        $fitmentRows = $validated['fitments'];
        $attributes = Arr::except($validated, ['is_staggered', 'fitments']);

        return [$attributes, $fitmentRows, $isStaggered];
    }

    /**
     * Replace a vehicle's fitment rows wholesale from the validated set,
     * rather than matching/updating individual rows by position — the
     * `is_staggered` toggle can change the row shape entirely (one `all`
     * row <-> two `front`/`rear` rows) between edits, and delete-then-
     * recreate sidesteps any `(vehicle_id, position)` unique-constraint
     * collision during that transition. The full set has already passed
     * `FitmentSetValidator` via `VehicleRequest::after()` before this runs.
     * `source` is hardcoded to `manual` here — never accepted from the
     * request, per the data model doc's "source is never admin-selectable"
     * rule.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceFitments(Vehicle $vehicle, array $rows, bool $isStaggered): void
    {
        $vehicle->fitments()->delete();

        foreach ($rows as $row) {
            $vehicle->fitments()->create([
                'position' => $row['position'],
                'width' => $row['width'],
                'profile' => $row['profile'],
                'rim_diameter' => $row['rim_diameter'],
                'load_index' => $row['load_index'] ?? null,
                'speed_rating' => $row['speed_rating'] ?? null,
                'is_staggered' => $isStaggered,
                'source' => VehicleFitmentSource::Manual,
                'confidence' => $row['confidence'],
                'notes' => $row['notes'] ?? null,
                'status' => Status::Active,
            ]);
        }
    }
}
