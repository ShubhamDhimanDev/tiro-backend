<?php

namespace App\Http\Requests\Api\Bookings;

use App\Http\Requests\Concerns\ValidatesBookingCart;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/bookings` body — see
 * docs/architecture/02-api-contract.md. Works guest or authenticated;
 * authorization is handled by the route's optional `auth:customer`
 * middleware, not here.
 */
class StoreBookingRequest extends FormRequest
{
    use ValidatesBookingCart;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge($this->cartRules(), [
            'service_zone_id' => ['required', 'integer', 'min:1'],
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            // Optional only for a flexible booking: the server then assigns
            // the concrete window (a given `slot_start` is tried first).
            'slot_start' => [Rule::requiredIf(fn (): bool => ! $this->boolean('flexible')), 'nullable', 'date_format:H:i'],
            'flexible' => ['sometimes', 'boolean'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'vehicle_id' => ['sometimes', 'nullable', 'integer', 'exists:vehicles,id'],
        ]);
    }
}
