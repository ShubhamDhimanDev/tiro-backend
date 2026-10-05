<?php

namespace App\Http\Requests\Api\Customer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/customer/addresses/{address}` body — partial update, every
 * field optional — see docs/architecture (Phase 7 readiness pass).
 */
class UpdateCustomerAddressRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'suburb_id' => ['sometimes', 'integer', 'exists:suburbs,id'],
            'line1' => ['sometimes', 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'access_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
