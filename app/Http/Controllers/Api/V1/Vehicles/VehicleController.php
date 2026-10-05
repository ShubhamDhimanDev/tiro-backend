<?php

namespace App\Http\Controllers\Api\V1\Vehicles;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vehicles\VehicleModelsRequest;
use App\Http\Requests\Api\Vehicles\VehicleYearsRequest;
use App\Http\Resources\VehicleSummaryResource;
use App\Http\Resources\VehicleYearResource;
use App\Models\Vehicle;
use App\Models\VehicleFitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The manual make -> model -> year -> fitment picker — see
 * docs/architecture/02-api-contract.md's "Vehicle identification & fitment
 * endpoints" section. Only `status = active` `Vehicle` rows are ever
 * returned. Deliberately queries the database directly: `make`/`model` are
 * small, bounded, distinct lookups against an indexed
 * `(make, model, year_from, year_to)` column set (see the `Vehicle`
 * migration), not free-text search.
 */
class VehicleController extends Controller
{
    public function makes(): JsonResponse
    {
        $makes = Vehicle::query()
            ->where('status', Status::Active)
            ->distinct()
            ->orderBy('make')
            ->pluck('make');

        return response()->json(['data' => $makes]);
    }

    public function models(VehicleModelsRequest $request): JsonResponse
    {
        $models = Vehicle::query()
            ->where('status', Status::Active)
            ->where('make', $request->validated('make'))
            ->distinct()
            ->orderBy('model')
            ->pluck('model');

        return response()->json(['data' => $models]);
    }

    public function years(VehicleYearsRequest $request): AnonymousResourceCollection
    {
        $vehicles = Vehicle::query()
            ->where('status', Status::Active)
            ->where('make', $request->validated('make'))
            ->where('model', $request->validated('model'))
            ->orderByDesc('year_from')
            ->get();

        return VehicleYearResource::collection($vehicles);
    }

    public function fitment(Vehicle $vehicle): JsonResponse
    {
        abort_if($vehicle->status !== Status::Active, 404);

        $fitments = $vehicle->fitments()->where('status', Status::Active)->get();

        $fitmentsByPosition = $fitments
            ->mapWithKeys(fn (VehicleFitment $fitment) => [
                $fitment->position->value => [
                    'width' => $fitment->width,
                    'profile' => $fitment->profile,
                    'rim_diameter' => $fitment->rim_diameter,
                    'load_index' => $fitment->load_index,
                    'speed_rating' => $fitment->speed_rating,
                    'confidence' => $fitment->confidence->value,
                ],
            ])
            ->all();

        return response()->json([
            'data' => [
                'vehicle' => (new VehicleSummaryResource($vehicle))->resolve(),
                // A vehicle with zero fitment rows configured has no
                // meaningful is_staggered value yet — defaults to false
                // rather than leaving the field absent, since the response
                // shape is fixed regardless of data-entry completeness.
                'is_staggered' => $fitments->isNotEmpty() && $fitments->first()->is_staggered,
                'fitments' => $fitmentsByPosition === [] ? (object) [] : $fitmentsByPosition,
            ],
        ]);
    }
}
