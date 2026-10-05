<?php

namespace App\Http\Resources;

use App\Models\Brand;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/brands/{slug}` — {@see BrandResource} plus the number of
 * active tyre models (`tyre_models_count`, loaded by the controller).
 *
 * @mixin Brand
 */
class BrandDetailResource extends BrandResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'tyre_model_count' => (int) ($this->tyre_models_count ?? 0),
        ];
    }
}
