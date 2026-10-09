<?php

namespace App\Services\Location;

use App\Enums\ServiceZoneType;
use App\Models\ServiceZone;
use App\Models\Suburb;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the public state > city > suburb coverage tree from the existing
 * zone/suburb data — nothing is stored separately.
 *
 * - A **city** is the set of serviceable zones (active zone, active state)
 *   sharing a `city_slug` (zones with no `city_name` are not listed publicly).
 * - A suburb belongs to a city when {@see ServiceabilityResolver}'s rule
 *   (exact `suburb_list` match first, else nearest radius zone, priority
 *   breaking ties) resolves it to one of that city's zones — i.e. the
 *   listing can never advertise a suburb that the serviceability check
 *   would then refuse or assign to a different zone.
 *
 * @phpstan-type SuburbRow array{name: string, slug: string, postcode: string, service_zone_id: int}
 * @phpstan-type CityRow array{name: string, slug: string, service_zone_id: int, service_zone_ids: list<int>, suburb_count: int, suburbs: list<SuburbRow>}
 * @phpstan-type StateRow array{code: string, name: string, slug: string, city_count: int, suburb_count: int, cities: list<CityRow>}
 */
class CoverageService
{
    public function __construct(private readonly ServiceabilityResolver $resolver) {}

    /**
     * @return list<StateRow>
     */
    public function tree(): array
    {
        /** @var EloquentCollection<int, ServiceZone> $zones */
        $zones = ServiceZone::query()
            ->serviceable()
            ->with(['state', 'suburbs:id'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $cityZones = $zones->filter(fn (ServiceZone $zone): bool => $zone->city_slug !== null);

        if ($cityZones->isEmpty()) {
            return [];
        }

        $assignments = $this->assignSuburbsToZones($zones, $cityZones);

        $states = [];

        foreach ($cityZones->groupBy('state_id') as $stateZones) {
            $state = $stateZones->first()->state;
            $cities = [];

            foreach ($stateZones->groupBy('city_slug') as $citySlug => $citySlugZones) {
                $zoneIds = $citySlugZones->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $suburbs = $this->suburbRows($assignments, $zoneIds);

                $cities[] = [
                    'name' => (string) $citySlugZones->first()->city_name,
                    'slug' => (string) $citySlug,
                    // Highest priority, then lowest id (the `$zones` order above).
                    'service_zone_id' => $zoneIds[0],
                    'service_zone_ids' => $zoneIds,
                    'suburb_count' => count($suburbs),
                    'suburbs' => $suburbs,
                ];
            }

            usort($cities, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

            $states[] = [
                'code' => $state->code,
                'name' => $state->name,
                'slug' => strtolower($state->code),
                'city_count' => count($cities),
                'suburb_count' => array_sum(array_column($cities, 'suburb_count')),
                'cities' => $cities,
            ];
        }

        usort($states, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $states;
    }

    /**
     * One city (with its zones, for coverage notes) or null.
     *
     * @return array{city: CityRow, state: array{code: string, name: string, slug: string}, zones: EloquentCollection<int, ServiceZone>}|null
     */
    public function city(string $stateSlug, string $citySlug): ?array
    {
        foreach ($this->tree() as $state) {
            if ($state['slug'] !== strtolower($stateSlug)) {
                continue;
            }

            foreach ($state['cities'] as $city) {
                if ($city['slug'] === $citySlug) {
                    return [
                        'city' => $city,
                        'state' => ['code' => $state['code'], 'name' => $state['name'], 'slug' => $state['slug']],
                        'zones' => ServiceZone::query()->whereIn('id', $city['service_zone_ids'])->orderByDesc('priority')->orderBy('id')->get(),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Assign every suburb in the relevant states to the zone the
     * serviceability rule would pick, in memory (one suburb query in total).
     *
     * @param  EloquentCollection<int, ServiceZone>  $activeZones  every active zone (city or not) — an uncited zone can still win a suburb
     * @param  Collection<int, ServiceZone>  $cityZones
     * @return array<int, list<Suburb>> zone id => suburbs assigned to it
     */
    private function assignSuburbsToZones(EloquentCollection $activeZones, Collection $cityZones): array
    {
        $suburbs = Suburb::query()
            ->whereIn('state_id', $cityZones->pluck('state_id')->unique())
            ->orderBy('name')
            ->get();

        $listMembers = $activeZones
            ->where('type', ServiceZoneType::SuburbList)
            ->mapWithKeys(fn (ServiceZone $zone): array => [$zone->id => array_flip($zone->suburbs->pluck('id')->all())]);

        $listZones = $activeZones->where('type', ServiceZoneType::SuburbList);
        $radiusZones = $activeZones->where('type', ServiceZoneType::Radius);

        $assigned = [];

        foreach ($suburbs as $suburb) {
            // `$activeZones` is already ordered priority desc, id asc, so the
            // first exact list match is the resolver's step-1 winner.
            $zone = $listZones->first(fn (ServiceZone $candidate): bool => isset($listMembers[$candidate->id][$suburb->id]))
                ?? $this->resolver->nearestRadiusZone($radiusZones, collect([$suburb]));

            // Defensive: a suburb is only ever listed under a zone in its own
            // state (a mis-keyed suburb whose coordinates fall inside another
            // state's zone is data-entry error, not coverage).
            if ($zone !== null && $zone->state_id === $suburb->state_id) {
                $assigned[$zone->id][] = $suburb;
            }
        }

        return $assigned;
    }

    /**
     * @param  array<int, list<Suburb>>  $assignments
     * @param  list<int>  $zoneIds
     * @return list<SuburbRow>
     */
    private function suburbRows(array $assignments, array $zoneIds): array
    {
        $rows = [];
        $slugCounts = [];

        foreach ($zoneIds as $zoneId) {
            foreach ($assignments[$zoneId] ?? [] as $suburb) {
                $rows[] = ['suburb' => $suburb, 'zone_id' => $zoneId];
                $slugCounts[Str::slug($suburb->name)] = ($slugCounts[Str::slug($suburb->name)] ?? 0) + 1;
            }
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['suburb']->name, $b['suburb']->name) ?: strcmp($a['suburb']->postcode, $b['suburb']->postcode));

        return array_map(function (array $row) use ($slugCounts): array {
            /** @var Suburb $suburb */
            $suburb = $row['suburb'];
            $slug = Str::slug($suburb->name);

            return [
                'name' => $suburb->name,
                // Same-named suburbs inside one city (different postcodes)
                // get the postcode appended so slugs stay unique.
                'slug' => $slugCounts[$slug] > 1 ? $slug.'-'.$suburb->postcode : $slug,
                'postcode' => $suburb->postcode,
                'service_zone_id' => $row['zone_id'],
            ];
        }, $rows);
    }
}
