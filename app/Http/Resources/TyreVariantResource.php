<?php

namespace App\Http\Resources;

use App\Models\TyreVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Browse/search result shape for `GET /api/v1/tyres` and
 * `GET /api/v1/tyres/latest-releases` — see
 * docs/architecture/02-api-contract.md. `stock_status`, `unit_price`,
 * `promotional_price`, and `currency` only appear when the controller
 * resolved a `zone` and attached the transient `stock_status` attribute;
 * otherwise they're omitted entirely (never fabricate a nationwide price —
 * same principle as the PDP `availability` endpoint's price/stock split).
 * `promotional_price` is always `null` for now — no promotions engine
 * exists yet.
 *
 * @mixin TyreVariant
 */
class TyreVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $zoneResolved = isset($this->resource->stock_status);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'slug' => $this->slug,
            'width' => $this->width,
            'profile' => $this->profile,
            'rim_diameter' => $this->rim_diameter,
            'load_index' => $this->load_index,
            'speed_rating' => $this->speed_rating,
            'sidewall' => $this->sidewall->value,
            // Brand-level tier (premium|mid|budget|null), mirrored from
            // `tyre_model.tier` so a card needs no nested lookup.
            'tier' => $this->whenLoaded('tyreModel', fn () => $this->tyreModel->brand?->tier?->value),
            // Phase 7: catalogue price per tyre in cents (GST-inclusive, before
            // promotions). Zone-independent today, so safe to show without a
            // location; `unit_price` below is still zone-gated.
            'list_price' => $this->base_price,
            'run_flat' => $this->whenLoaded('tyreModel', fn () => (bool) $this->tyreModel->run_flat),
            'pattern' => $this->whenLoaded('tyreModel', fn () => $this->tyreModel->slug),
            'four_for_three' => (bool) ($this->resource->getAttribute('four_for_three') ?? false),
            'tyre_model' => new TyreModelSummaryResource($this->whenLoaded('tyreModel')),
            'unit_price' => $this->when($zoneResolved, fn () => $this->base_price),
            'promotional_price' => $this->when($zoneResolved, fn () => null),
            'currency' => $this->when($zoneResolved, fn () => 'AUD'),
            'stock_status' => $this->when($zoneResolved, fn () => $this->resource->stock_status),
        ];
    }
}
