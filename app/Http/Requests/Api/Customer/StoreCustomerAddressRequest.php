<?php

namespace App\Http\Requests\Api\Customer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/customer/addresses` body — see docs/architecture (Phase 7
 * readiness pass). Mirrors `StoreOrderRequest`'s `address.*` rules exactly
 * (same bounds); `type` is always `fitting`, not client-settable — see
 * `Customer\AddressController::store()`.
 */
class StoreCustomerAddressRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'suburb_id' => ['required', 'integer', 'exists:suburbs,id'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'access_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
