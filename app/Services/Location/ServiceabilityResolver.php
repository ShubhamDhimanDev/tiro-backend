<?php

namespace App\Services\Location;

use App\Enums\ServiceZoneType;
use App\Models\ServiceZone;
use App\Models\Suburb;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Implements the "Zone resolution / overlap rule" from
 * docs/architecture/02-api-contract.md (added 2026-09-10), evaluated against
 * serviceable zones only (`ACTIVE` status, in an active state):
 *
 * 1. An exact `suburb_list` match wins first.
 * 2. Else, evaluate `radius` zones — the suburb's lat/lng must fall within
 *    `radius_km` of `origin_lat`/`origin_lng`; if multiple match, the
 *    nearest origin wins.
 * 3. If still tied, `ServiceZone.priority` (higher wins) breaks the tie.
 * 4. No match at any step → not serviceable.
 */
class ServiceabilityResolver
{
    /**
     * Tolerance (km) within which two radius-zone distances are treated as
     * tied for the purpose of falling through to the priority tie-break —
     * exact float equality between independently computed haversine
     * distances is not reliable.
     */
    private const DISTANCE_TIE_TOLERANCE_KM = 0.001;

    /**
     * @param  Collection<int, Suburb>  $suburbs  Candidate suburb rows
     *                                            resolved from the caller's postcode/suburb-name lookup. More
     *                                            than one may legitimately match (a postcode spanning several
     *                                            suburbs) — every candidate is considered, and the single best
     *                                            zone across all of them wins.
     */
    public function resolve(Collection $suburbs): ?ServiceZone
    {
        if ($suburbs->isEmpty()) {
            return null;
        }

        return $this->resolveSuburbListMatch($suburbs) ?? $this->resolveRadiusMatch($suburbs);
    }

    /**
     * Step 1: exact `suburb_list` membership. Every match is equally
     * "exact", so ties (including the admin-data-entry-conflict case of two
     * `suburb_list` zones both containing the suburb) go straight to
     * `priority` — there is no distance concept at this step.
     *
     * @param  Collection<int, Suburb>  $suburbs
     * @return ServiceZone|null The highest-priority `suburb_list` zone
     *                          containing any candidate suburb, or `null` if none match.
     */
    private function resolveSuburbListMatch(Collection $suburbs): ?ServiceZone
    {
        return ServiceZone::query()
            ->serviceable()
            ->where('type', ServiceZoneType::SuburbList)
            ->whereHas('suburbs', fn ($query) => $query->whereIn('suburbs.id', $suburbs->pluck('id')))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();
    }

    /**
     * Step 2-3: nearest-origin `radius` zone wins; identical (within
     * tolerance) distances fall back to `priority`.
     *
     * @param  Collection<int, Suburb>  $suburbs
     * @return ServiceZone|null The nearest (priority-tie-broken) `radius`
     *                          zone whose `radius_km` covers any candidate suburb, or `null` if none match.
     */
    private function resolveRadiusMatch(Collection $suburbs): ?ServiceZone
    {
        $radiusZones = ServiceZone::query()
            ->serviceable()
            ->where('type', ServiceZoneType::Radius)
            ->get();

        return $this->nearestRadiusZone($radiusZones, $suburbs);
    }

    /**
     * The nearest-origin (priority-tie-broken) zone among `$radiusZones`
     * covering any of `$suburbs`. Extracted so the public coverage listing
     * (App\Services\Location\CoverageService) can assign many suburbs in
     * memory with exactly the same rule as a single serviceability check.
     *
     * @param  SupportCollection<int, ServiceZone>  $radiusZones
     * @param  Collection<int, Suburb>  $suburbs
     */
    public function nearestRadiusZone(SupportCollection $radiusZones, SupportCollection $suburbs): ?ServiceZone
    {
        $best = null;
        $bestDistanceKm = null;

        foreach ($radiusZones as $zone) {
            foreach ($suburbs as $suburb) {
                $distanceKm = Geo::haversineKm(
                    (float) $zone->origin_lat,
                    (float) $zone->origin_lng,
                    (float) $suburb->lat,
                    (float) $suburb->lng,
                );

                if ($distanceKm > (float) $zone->radius_km) {
                    continue;
                }

                if ($best === null) {
                    $best = $zone;
                    $bestDistanceKm = $distanceKm;

                    continue;
                }

                $diff = $distanceKm - $bestDistanceKm;

                if ($diff < -self::DISTANCE_TIE_TOLERANCE_KM) {
                    $best = $zone;
                    $bestDistanceKm = $distanceKm;
                } elseif (abs($diff) <= self::DISTANCE_TIE_TOLERANCE_KM && $zone->priority > $best->priority) {
                    $best = $zone;
                    $bestDistanceKm = $distanceKm;
                }
            }
        }

        return $best;
    }
}
