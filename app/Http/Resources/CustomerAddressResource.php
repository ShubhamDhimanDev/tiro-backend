<?php

namespace App\Http\Resources;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET|POST|PATCH /api/v1/customer/addresses` item shape — see
 * docs/architecture (Phase 7 readiness pass).
 *
 * @mixin Address
 */
class CustomerAddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'postcode' => $this->postcode,
            'suburb' => [
                'id' => $this->suburb->id,
                'name' => $this->suburb->name,
                'state' => $this->suburb->state->code,
            ],
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'access_instructions' => $this->access_instructions,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
