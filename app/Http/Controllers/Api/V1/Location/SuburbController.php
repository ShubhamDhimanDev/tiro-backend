<?php

namespace App\Http\Controllers\Api\V1\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Location\SuburbLookupRequest;
use App\Http\Requests\Api\Location\SuburbSearchRequest;
use App\Http\Resources\SuburbResource;
use App\Models\Suburb;
use App\Services\Location\ServiceabilityResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * `GET /api/v1/suburbs` — resolves a postcode + suburb name (the fields a
 * Google Places-picked address always carries) to the `Suburb.id` that
 * `POST /api/v1/orders`'s `address.suburb_id` requires. Added to close a
 * genuine contract gap frontend-agent flagged: `ServiceabilityController`
 * resolves candidate `Suburb` rows internally to reach a `ServiceZone`, but
 * never surfaced which row (or its id) matched, and nothing else in the API
 * did either.
 *
 * Mirrors `ServiceabilityController::candidateSuburbs()`'s query rather than
 * introducing a second parallel matching implementation, with one deliberate
 * difference: that lookup accepts postcode *or* suburb name (it only needs
 * "any candidate resolves to the same zone"), whereas this endpoint requires
 * both together, since it must resolve to a specific row.
 *
 * Ambiguity — more than one `Suburb` sharing a postcode+name, which the
 * schema permits since the unique constraint is `(name, state_id,
 * postcode)`, e.g. the same suburb name/postcode pair legitimately reused
 * across two states — is not collapsed server-side. Every matching row is
 * returned; the caller (which already has its own `state` from Places)
 * disambiguates using the `state` field each row carries. A single match is
 * simply the common case of the same response shape, not a different one.
 */
class SuburbController extends Controller
{
    public function index(SuburbLookupRequest $request): AnonymousResourceCollection
    {
        $suburbs = Suburb::query()
            ->with('state')
            ->where('postcode', $request->validated('postcode'))
            ->whereRaw('LOWER(name) = ?', [Str::lower((string) $request->validated('name'))])
            ->orderBy('name')
            ->get();

        return SuburbResource::collection($suburbs);
    }

    /**
     * `GET /api/v1/suburbs/search` — typeahead for the fitting location.
     * Matches a suburb-name prefix or a postcode prefix, exact-prefix first,
     * and reports whether (and by which zone) each suburb is served using
     * the same resolver as `POST /serviceability`.
     */
    public function search(SuburbSearchRequest $request, ServiceabilityResolver $resolver): JsonResponse
    {
        $term = Str::lower((string) $request->validated('q'));
        $escaped = addcslashes($term, '%_\\');

        $suburbs = Suburb::query()
            ->with('state')
            ->where(function ($query) use ($escaped): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$escaped.'%'])
                    ->orWhere('postcode', 'like', $escaped.'%');
            })
            ->orderByRaw('CASE WHEN LOWER(name) = ? THEN 0 ELSE 1 END', [$term])
            ->orderBy('name')
            ->orderBy('postcode')
            ->limit($request->integer('limit', 8))
            ->get();

        return response()->json(['data' => $suburbs->map(function (Suburb $suburb) use ($resolver): array {
            $zone = $resolver->resolve(new EloquentCollection([$suburb]));

            return [
                'id' => $suburb->id,
                'name' => $suburb->name,
                'state' => $suburb->state->code,
                'postcode' => $suburb->postcode,
                'label' => "{$suburb->name} {$suburb->state->code} {$suburb->postcode}",
                'serviceable' => $zone !== null,
                'service_zone_id' => $zone?->id,
            ];
        })->values()->all()]);
    }
}
