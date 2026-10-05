<?php

namespace App\Http\Resources;

use App\Models\TyreVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/tyres/{slug}` PDP static-content shape — see
 * docs/architecture/02-api-contract.md. Cacheable, no price/stock; those
 * live exclusively behind `GET /api/v1/tyres/{slug}/availability`.
 *
 * @mixin TyreVariant
 */
class TyreVariantDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'width' => $this->width,
            'profile' => $this->profile,
            'rim_diameter' => $this->rim_diameter,
            'load_index' => $this->load_index,
            'speed_rating' => $this->speed_rating,
            'sidewall' => $this->sidewall->value,
            'list_price' => $this->base_price,
            'four_for_three' => (bool) ($this->resource->getAttribute('four_for_three') ?? false),
            'tyre_model' => new TyreModelDetailResource($this->whenLoaded('tyreModel')),
        ];
    }
}
