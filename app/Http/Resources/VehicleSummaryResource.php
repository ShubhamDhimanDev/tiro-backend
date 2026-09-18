<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `"vehicle"` object nested in `GET /api/v1/vehicles/{vehicle}/fitment`
 * — see docs/architecture/02-api-contract.md.
 *
 * @mixin Vehicle
 */
class VehicleSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'series' => $this->series,
            'year_from' => $this->year_from,
            'year_to' => $this->year_to,
            'body_type' => $this->body_type,
        ];
    }
}
