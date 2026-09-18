<?php

namespace App\Http\Resources;

use App\Models\TyreModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `tyre_model` shape nested inside browse/search results — see
 * docs/architecture/02-api-contract.md's `GET /api/v1/tyres`. Deliberately
 * lighter than {@see TyreModelDetailResource} (no description/warranty/
 * service_inclusions), enough for frontend-agent to group variants into
 * "from $X" cards.
 *
 * @mixin TyreModel
 */
class TyreModelSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'images' => $this->images,
            'category' => $this->category->value,
            'tyre_type' => $this->tyre_type->value,
            'brand' => new BrandResource($this->whenLoaded('brand')),
        ];
    }
}
