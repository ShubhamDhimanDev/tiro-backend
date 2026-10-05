<?php

namespace App\Http\Requests\Api\Bookings;

use App\Http\Requests\Concerns\ValidatesBookingCart;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * `GET /api/v1/booking-slots` query params — see
 * docs/architecture/02-api-contract.md. `zone`/`date_from`/`date_to` are
 * required; the date range is capped at 14 days (`422` if exceeded).
 */
class BookingSlotsRequest extends FormRequest
{
    use ValidatesBookingCart;

    private const MAX_RANGE_DAYS = 14;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge($this->cartRules(), [
            'zone' => ['required', 'integer', 'min:1'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('date_from') || ! $this->filled('date_to')) {
                return;
            }

            // The `date_format:Y-m-d` rule above has already passed by the
            // time this `after()` hook runs, so both values are guaranteed
            // well-formed here.
            $from = Carbon::createFromFormat('Y-m-d', (string) $this->input('date_from'));
            $to = Carbon::createFromFormat('Y-m-d', (string) $this->input('date_to'));

            if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('date_to', __('The date range cannot exceed :days days.', ['days' => self::MAX_RANGE_DAYS]));
            }
        });
    }
}
