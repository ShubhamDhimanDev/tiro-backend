<?php

namespace App\Http\Requests\Api\Customer;

use App\Rules\SavedFitmentShape;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/customer/vehicles/{vehicle}` body — partial update, every
 * field optional (including `saved_fitment`, unlike the store request where
 * it's required) — see docs/architecture (Phase 7 readiness pass).
 */
class UpdateCustomerVehicleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rego' => ['sometimes', 'nullable', 'string', 'max:20'],
            'state' => ['sometimes', 'nullable', 'string', 'max:10'],
            'vin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'vehicle_id' => ['sometimes', 'nullable', 'integer', 'exists:vehicles,id'],
            'saved_fitment' => ['sometimes', 'array', new SavedFitmentShape],
        ];
    }
}
