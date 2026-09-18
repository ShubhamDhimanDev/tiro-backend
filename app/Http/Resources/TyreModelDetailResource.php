<?php

namespace App\Http\Resources;

use App\Models\TyreModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `tyre_model` shape nested inside the PDP static-content endpoint —
 * see docs/architecture/02-api-contract.md's `GET /api/v1/tyres/{slug}`.
 * No price/stock — those live exclusively behind the availability endpoint.
 *
 * @mixin TyreModel
 */
class TyreModelDetailResource extends JsonResource
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
            'description' => $this->description,
            'warranty_text' => $this->warranty_text,
            'warranty_km' => $this->warranty_km,
            'run_flat' => $this->run_flat,
            'construction' => $this->construction->value,
            'service_inclusions' => $this->service_inclusions,
            'images' => $this->images,
            'category' => $this->category->value,
            'tyre_type' => $this->tyre_type->value,
            'brand' => new BrandResource($this->whenLoaded('brand')),
        ];
    }
}
