<?php

namespace App\Http\Resources;

use App\Models\PopularSize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PopularSize
 */
class PopularSizeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'width' => $this->width,
            'profile' => $this->profile,
            'rim_diameter' => $this->rim_diameter,
        ];
    }
}
