<?php

namespace App\Http\Resources;

use App\Enums\PromotionType;
use App\Models\Brand;
use App\Models\Promotion;
use App\Services\Promotions\OfferTargetResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public offer shape for `GET /api/v1/offers[/{slug}]`. Deliberately omits
 * every internal/commercial field (usage counters, stock limit, status,
 * stackable flag). `brand`/`shop_filters`/`zone_ids` come from
 * {@see OfferTargetResolver}, attached by the
 * controller as the transient `offer_target` attribute.
 *
 * @mixin Promotion
 */
class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{brand: Brand|null, category: string|null, zone_ids: list<int>} $target */
        $target = $this->resource->getAttribute('offer_target') ?? ['brand' => null, 'category' => null, 'zone_ids' => []];

        $shopFilters = array_filter([
            'brand' => $target['brand']?->slug,
            'category' => $target['category'],
        ]);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title ?: $this->name,
            'summary' => $this->summary,
            'brand' => $target['brand'] === null ? null : [
                'name' => $target['brand']->name,
                'slug' => $target['brand']->slug,
                'logo_path' => $target['brand']->logo_path,
            ],
            'discount_description' => $this->discount_description ?: $this->derivedDiscountDescription(),
            'badge_text' => $this->badge_text ?: $this->derivedBadge(),
            'code' => $this->code,
            'starts_at' => $this->starts_at->toDateString(),
            'ends_at' => $this->ends_at->toDateString(),
            'terms' => $this->terms,
            'image_path' => $this->image_path,
            // Query params for `GET /api/v1/tyres` / the storefront's
            // `/tyres` page; an empty object when the offer is not
            // brand/category-scoped.
            'shop_filters' => (object) $shopFilters,
            // Empty = valid in every service zone.
            'zone_ids' => $target['zone_ids'],
        ];
    }

    private function derivedDiscountDescription(): string
    {
        return match ($this->type) {
            PromotionType::Percentage => "{$this->value}% off",
            PromotionType::Fixed => '$'.$this->formatDollars($this->value).' off each tyre',
            PromotionType::FourForThree => 'Buy 3, get the 4th free',
            PromotionType::Bundle, PromotionType::BuyXGetY => $this->name,
        };
    }

    private function derivedBadge(): string
    {
        return match ($this->type) {
            PromotionType::Percentage => "{$this->value}% off",
            PromotionType::Fixed => '$'.$this->formatDollars($this->value).' off',
            PromotionType::FourForThree => '4 for 3',
            PromotionType::Bundle, PromotionType::BuyXGetY => 'Special offer',
        };
    }

    private function formatDollars(int $cents): string
    {
        return $cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2);
    }
}
