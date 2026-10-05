<?php

namespace App\Http\Resources;

use App\Models\Suburb;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/suburbs` list item — see
 * docs/architecture/02-api-contract.md. `id` is the exact value
 * `POST /api/v1/orders`'s `address.suburb_id` expects. `state` is the
 * state's short code (e.g. "VIC"), the same format Google Places' own
 * `administrative_area_level_1` short name uses, so the caller can
 * cross-reference it directly without a lookup of its own.
 *
 * @mixin Suburb
 */
class SuburbResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'state' => $this->state->code,
            'postcode' => $this->postcode,
        ];
    }
}
