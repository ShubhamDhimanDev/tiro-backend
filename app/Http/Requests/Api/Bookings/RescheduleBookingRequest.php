<?php

namespace App\Http\Requests\Api\Bookings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/bookings/{booking}/reschedule` body — see
 * docs/architecture/02-api-contract.md. Cart contents (`items`/`addons`)
 * are not editable here; a cart change is a different booking, not a
 * reschedule of this one.
 */
class RescheduleBookingRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'slot_start' => ['required', 'date_format:H:i'],
        ];
    }
}
