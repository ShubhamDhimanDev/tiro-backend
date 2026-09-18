<?php

namespace App\Http\Controllers\Api\V1\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Location\ServiceabilityCheckRequest;
use App\Models\Suburb;
use App\Services\Location\ServiceabilityResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * `POST /api/v1/serviceability` — the server is the sole source of truth
 * for zone resolution; see docs/architecture/02-api-contract.md's
 * "Location/serviceability state flow" and "Zone resolution / overlap
 * rule" sections.
 */
class ServiceabilityController extends Controller
{
    public function __construct(private readonly ServiceabilityResolver $resolver) {}

    public function check(ServiceabilityCheckRequest $request): JsonResponse
    {
        $zone = $this->resolver->resolve($this->candidateSuburbs($request));

        return response()->json([
            'serviceable' => $zone !== null,
            'service_zone_id' => $zone?->id,
            'label' => $zone?->name,
            // Not yet computed — no "nearby serviceable area" suggestion
            // logic exists yet; reserved placeholder per the documented
            // response shape.
            'suggested_areas' => [],
        ]);
    }

    /**
     * @return Collection<int, Suburb>
     */
    private function candidateSuburbs(ServiceabilityCheckRequest $request): Collection
    {
        if ($postcode = $request->validated('postcode')) {
            return Suburb::query()->where('postcode', $postcode)->get();
        }

        $suburbName = (string) $request->validated('suburb');

        return Suburb::query()->whereRaw('LOWER(name) = ?', [Str::lower($suburbName)])->get();
    }
}
