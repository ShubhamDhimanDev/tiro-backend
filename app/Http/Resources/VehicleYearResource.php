<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/vehicles/years` list item — see
 * docs/architecture/02-api-contract.md. `id` is the same id the fitment
 * endpoint resolves against; there's no separate "confirm vehicle" step.
 *
 * @mixin Vehicle
 */
class VehicleYearResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year_from' => $this->year_from,
            'year_to' => $this->year_to,
            'series' => $this->series,
            'body_type' => $this->body_type,
        ];
    }
}
