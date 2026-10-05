<?php

namespace App\Http\Resources;

use App\Models\CustomerVehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET|POST|PATCH /api/v1/customer/vehicles` item shape — see
 * docs/architecture (Phase 7 readiness pass). `vehicle` is a nested summary,
 * present only when `vehicle_id` is set.
 *
 * @mixin CustomerVehicle
 */
class CustomerVehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'rego' => $this->rego,
            'state' => $this->state,
            'vin' => $this->vin,
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->vehicle_id === null ? null : new VehicleSummaryResource($this->vehicle),
            'saved_fitment' => $this->saved_fitment,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
