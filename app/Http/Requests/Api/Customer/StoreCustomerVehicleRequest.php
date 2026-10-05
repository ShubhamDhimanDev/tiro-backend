<?php

namespace App\Http\Requests\Api\Customer;

use App\Rules\SavedFitmentShape;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/customer/vehicles` body — see docs/architecture (Phase 7
 * readiness pass). `auth:customer`-only, enforced at the route level.
 * `saved_fitment` is required and shape-validated by
 * {@see SavedFitmentShape} — see that rule's docblock for the two accepted
 * shapes.
 */
class StoreCustomerVehicleRequest extends FormRequest
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
            'saved_fitment' => ['required', 'array', new SavedFitmentShape],
        ];
    }
}
