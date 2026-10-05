<?php

namespace App\Services\Promotions;

use App\Enums\PromotionEligibilityScope;
use App\Models\Brand;
use App\Models\Promotion;
use App\Models\TyreModel;
use Illuminate\Support\Collection;

/**
 * Resolves what a public offer targets, in storefront terms, from the
 * promotion's existing eligibility rows — no separate "target" data to keep
 * in sync. Batched: one query per scope type for a whole listing.
 *
 * `brand`: the first brand-scoped (or model-scoped, via its brand)
 * eligibility. `category`: the first category-scoped eligibility. These map
 * 1:1 onto `GET /api/v1/tyres`' `brand` and `category` filters, which is
 * what "Shop this offer" links to. Offers scoped only to specific variants
 * yield no filters (the storefront links to the plain listing).
 */
class OfferTargetResolver
{
    /**
     * @param  Collection<int, Promotion>  $promotions  with `eligibilities` loaded
     * @return array<int, array{brand: Brand|null, category: string|null, zone_ids: list<int>}> keyed by promotion id
     */
    public function resolve(Collection $promotions): array
    {
        $eligibilities = $promotions->flatMap(fn (Promotion $promotion) => $promotion->eligibilities);

        $brandIds = $eligibilities->where('scope', PromotionEligibilityScope::Brand)->pluck('scope_id')->map(fn ($id) => (int) $id)->unique();
        $modelIds = $eligibilities->where('scope', PromotionEligibilityScope::TyreModel)->pluck('scope_id')->map(fn ($id) => (int) $id)->unique();

        $models = TyreModel::query()->whereIn('id', $modelIds)->get(['id', 'brand_id'])->keyBy('id');
        $brands = Brand::query()->whereIn('id', $brandIds->merge($models->pluck('brand_id'))->unique())->get()->keyBy('id');

        $resolved = [];

        foreach ($promotions as $promotion) {
            $brand = null;
            $category = null;

            foreach ($promotion->eligibilities as $eligibility) {
                if ($brand === null && $eligibility->scope === PromotionEligibilityScope::Brand) {
                    $brand = $brands->get((int) $eligibility->scope_id);
                }

                if ($brand === null && $eligibility->scope === PromotionEligibilityScope::TyreModel) {
                    $brand = $brands->get($models->get((int) $eligibility->scope_id)?->brand_id);
                }

                if ($category === null && $eligibility->scope === PromotionEligibilityScope::Category) {
                    $category = $eligibility->scope_id;
                }
            }

            $zoneIds = $promotion->eligibilities->pluck('service_zone_id');

            $resolved[$promotion->id] = [
                'brand' => $brand,
                'category' => $category,
                // Empty = applies everywhere (any unscoped eligibility row
                // makes the offer valid in every zone).
                'zone_ids' => $zoneIds->contains(null) ? [] : $zoneIds->filter()->unique()->values()->map(fn ($id) => (int) $id)->all(),
            ];
        }

        return $resolved;
    }
}
