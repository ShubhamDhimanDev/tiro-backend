<?php

namespace App\Http\Requests\Api\Bookings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * `GET /api/v1/booking-availability` query params. One of `postcode`,
 * `suburb` or `zone` locates the fitting; the date range is at most 14 days.
 */
class BookingAvailabilityRequest extends FormRequest
{
    private const MAX_RANGE_DAYS = 14;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'postcode' => ['required_without_all:suburb,zone', 'nullable', 'string', 'regex:/^\d{4}$/'],
            'suburb' => ['required_without_all:postcode,zone', 'nullable', 'string', 'min:2', 'max:255'],
            'zone' => ['required_without_all:postcode,suburb', 'nullable', 'integer', 'min:1'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'quantity' => ['sometimes', 'integer', 'between:1,8'],
            'tyre_variant_id' => ['sometimes', 'integer', 'exists:tyre_variants,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = Carbon::createFromFormat('Y-m-d', (string) $this->input('date_from'));
            $to = Carbon::createFromFormat('Y-m-d', (string) $this->input('date_to'));

            if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('date_to', __('The date range cannot exceed :days days.', ['days' => self::MAX_RANGE_DAYS]));
            }
        });
    }
}
