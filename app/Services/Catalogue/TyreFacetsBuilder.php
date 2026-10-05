<?php

namespace App\Services\Catalogue;

use App\Models\TyreVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the `GET /api/v1/tyres/facets` payload from an already scoped tyre
 * variant query (joined to `tyre_models` and `brands`). Aggregates in PHP
 * from one lightweight row query: the scope is a size (tens of rows) or at
 * worst the whole active catalogue (hundreds).
 */
class TyreFacetsBuilder
{
    private const TIER_ORDER = ['premium', 'mid', 'budget'];

    /**
     * @param  Builder<TyreVariant>  $query
     * @return array<string, mixed>
     */
    public function build(Builder $query): array
    {
        /** @var Collection<int, object> $rows */
        $rows = $query->reorder()->select([
            'tyre_variants.id', 'tyre_variants.width', 'tyre_variants.profile', 'tyre_variants.rim_diameter',
            'tyre_variants.load_index', 'tyre_variants.speed_rating', 'tyre_variants.base_price',
            'tyre_models.name as model_name', 'tyre_models.slug as model_slug', 'tyre_models.tyre_type',
            'tyre_models.category', 'tyre_models.run_flat',
            'brands.name as brand_name', 'brands.slug as brand_slug', 'brands.tier as brand_tier',
        ])->toBase()->get();

        if ($rows->isEmpty()) {
            return [
                'total' => 0, 'brands' => [], 'patterns' => [], 'tyre_types' => [], 'categories' => [], 'tiers' => [],
                'run_flat' => ['yes' => 0, 'no' => 0], 'price' => null, 'load_index' => null, 'speed_ratings' => [], 'car_makes' => [],
            ];
        }

        $loads = $rows->map(fn (object $row): int => (int) $row->load_index)->filter();

        return [
            'total' => $rows->count(),
            'brands' => $rows->groupBy('brand_slug')->map(fn (Collection $group, string $slug): array => [
                'slug' => $slug, 'name' => $group->first()->brand_name, 'tier' => $group->first()->brand_tier, 'count' => $group->count(),
            ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'patterns' => $rows->groupBy('model_slug')->map(fn (Collection $group, string $slug): array => [
                'slug' => $slug, 'name' => $group->first()->model_name, 'brand_slug' => $group->first()->brand_slug,
                'brand_name' => $group->first()->brand_name, 'tier' => $group->first()->brand_tier, 'count' => $group->count(),
            ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'tyre_types' => $this->counts($rows, 'tyre_type'),
            'categories' => $this->counts($rows, 'category'),
            'tiers' => collect(self::TIER_ORDER)
                ->map(fn (string $tier): array => ['value' => $tier, 'count' => $rows->where('brand_tier', $tier)->count()])
                ->filter(fn (array $tier): bool => $tier['count'] > 0)->values()->all(),
            'run_flat' => ['yes' => $rows->where('run_flat', 1)->count(), 'no' => $rows->where('run_flat', 0)->count()],
            'price' => ['min' => (int) $rows->min('base_price'), 'max' => (int) $rows->max('base_price')],
            'load_index' => $loads->isEmpty() ? null : ['min' => $loads->min(), 'max' => $loads->max()],
            'speed_ratings' => $rows->pluck('speed_rating')->unique()
                ->sortBy(fn (string $rating): int|false => array_search($rating, TyreSearchFilters::SPEED_ORDER, true))->values()->all(),
            'car_makes' => $this->carMakes($rows),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array{value: string, count: int}>
     */
    private function counts(Collection $rows, string $column): array
    {
        return $rows->groupBy($column)->map(fn (Collection $group, string $value): array => ['value' => $value, 'count' => $group->count()])
            ->sortBy('value')->values()->all();
    }

    /**
     * Makes (with vehicle counts) that have an active fitment in one of the
     * sizes present in the scope.
     *
     * @param  Collection<int, object>  $rows
     * @return list<array{make: string, count: int}>
     */
    private function carMakes(Collection $rows): array
    {
        $sizes = $rows->map(fn (object $row): array => [$row->width, $row->profile, $row->rim_diameter])->unique()->values();

        if ($sizes->isEmpty() || $sizes->count() > 150) {
            return [];
        }

        return DB::table('vehicle_fitments')
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_fitments.vehicle_id')
            ->where('vehicle_fitments.status', 'active')
            ->where('vehicles.status', 'active')
            ->where(function ($query) use ($sizes): void {
                foreach ($sizes as [$width, $profile, $rim]) {
                    $query->orWhere(fn ($size) => $size
                        ->where('vehicle_fitments.width', $width)
                        ->where('vehicle_fitments.profile', $profile)
                        ->where('vehicle_fitments.rim_diameter', $rim));
                }
            })
            ->groupBy('vehicles.make')
            ->orderBy('vehicles.make')
            ->get(['vehicles.make', DB::raw('COUNT(DISTINCT vehicles.id) as vehicle_count')])
            ->map(fn (object $row): array => ['make' => $row->make, 'count' => (int) $row->vehicle_count])
            ->values()->all();
    }
}
