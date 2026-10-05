<?php

namespace App\Services\Catalogue;

use App\Models\TyreVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The optional, non-size filters shared by `GET /api/v1/tyres` and
 * `GET /api/v1/tyres/facets`. Applied to a query that already joins
 * `tyre_models` and `brands` (see `TyreController::baseQuery()`).
 *
 * Every list filter is a CSV (`brand=michelin,bridgestone`) meaning OR within
 * the filter and AND across filters. A single value behaves exactly like the
 * pre-Phase-7 single-value params.
 */
class TyreSearchFilters
{
    /** Low to high; `ZR` sits above `Y` by convention. */
    public const SPEED_ORDER = ['N', 'P', 'Q', 'R', 'S', 'T', 'U', 'H', 'V', 'W', 'Y', 'ZR'];

    /**
     * @param  Builder<TyreVariant>  $query
     * @param  array<string, mixed>  $filters  validated request input
     * @return Builder<TyreVariant>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if ($brands = self::csv($filters['brand'] ?? null)) {
            $query->whereIn('brands.slug', $brands);
        }

        if ($types = self::csv($filters['tyre_type'] ?? null)) {
            $query->whereIn('tyre_models.tyre_type', $types);
        }

        if ($categories = self::csv($filters['category'] ?? null)) {
            $query->whereIn('tyre_models.category', $categories);
        }

        if ($tiers = self::csv($filters['tier'] ?? null)) {
            $query->whereIn('brands.tier', $tiers);
        }

        if ($patterns = self::csv($filters['pattern'] ?? null)) {
            $query->whereIn('tyre_models.slug', $patterns);
        }

        if (isset($filters['min_load']) && $filters['min_load'] !== '') {
            $query->whereRaw('CAST(tyre_variants.load_index AS UNSIGNED) >= ?', [(int) $filters['min_load']]);
        }

        if (isset($filters['min_speed']) && $filters['min_speed'] !== '') {
            $query->whereIn('tyre_variants.speed_rating', self::speedsAtOrAbove((string) $filters['min_speed']));
        }

        if (($runFlat = self::runFlat($filters['runflat'] ?? null)) !== null) {
            $query->where('tyre_models.run_flat', $runFlat);
        }

        if (isset($filters['price_min']) && $filters['price_min'] !== '') {
            $query->where('tyre_variants.base_price', '>=', (int) $filters['price_min'] * 100);
        }

        if (isset($filters['price_max']) && $filters['price_max'] !== '') {
            $query->where('tyre_variants.base_price', '<=', (int) $filters['price_max'] * 100);
        }

        if (isset($filters['car_make']) && $filters['car_make'] !== '') {
            $query->whereExists(fn ($fitment) => $fitment
                ->select(DB::raw(1))
                ->from('vehicle_fitments')
                ->join('vehicles', 'vehicles.id', '=', 'vehicle_fitments.vehicle_id')
                ->whereColumn('vehicle_fitments.width', 'tyre_variants.width')
                ->whereColumn('vehicle_fitments.profile', 'tyre_variants.profile')
                ->whereColumn('vehicle_fitments.rim_diameter', 'tyre_variants.rim_diameter')
                ->where('vehicle_fitments.status', 'active')
                ->where('vehicles.status', 'active')
                ->where('vehicles.make', (string) $filters['car_make']));
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    public static function csv(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== '')));
    }

    /**
     * @return list<string>
     */
    public static function speedsAtOrAbove(string $minimum): array
    {
        $index = array_search(strtoupper($minimum), self::SPEED_ORDER, true);

        return $index === false ? [] : array_slice(self::SPEED_ORDER, $index);
    }

    public static function runFlat(mixed $value): ?bool
    {
        return match (is_string($value) ? strtolower($value) : $value) {
            'yes', '1', 'true', true, 1 => true,
            'no', '0', 'false', false, 0 => false,
            default => null,
        };
    }
}
