<?php

namespace App\Http\Requests\Api\Vehicles;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/vehicles/models` query params — see
 * docs/architecture/02-api-contract.md. `make` is required; an unrecognized
 * make is a normal `200` with `data: []`, decided in the controller, not
 * here.
 */
class VehicleModelsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'make' => ['required', 'string', 'max:100'],
        ];
    }
}
