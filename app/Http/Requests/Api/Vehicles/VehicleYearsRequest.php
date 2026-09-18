<?php

namespace App\Http\Requests\Api\Vehicles;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/vehicles/years` query params — see
 * docs/architecture/02-api-contract.md. `make`/`model` are both required.
 */
class VehicleYearsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
        ];
    }
}
