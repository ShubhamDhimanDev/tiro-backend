<?php

namespace App\Http\Requests\Api\Orders;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/orders` body — see docs/architecture/02-api-contract.md's
 * "Cart, Checkout & Payment endpoints" section. Works guest or
 * authenticated, same posture as booking creation; the booking's
 * `authorizeGuestOrOwner()` ownership check happens in the controller, not
 * here.
 *
 * `vehicle.rego`/`vehicle.state` are accepted (shape-validated) but not
 * currently persisted anywhere — see `OrderController`'s docblock for why;
 * flagged there rather than silently dropped without comment.
 *
 * `customer.mobile` Phase 7 fix: previously only shape-validated
 * (`string`/`max:30`), nothing enforced the E.164 format the rest of the
 * system already assumes (`Customer::routeNotificationForSms()`, the `sms`
 * notification channel). Now rejects anything that isn't E.164 with a
 * `422` — no server-side reformatting/guessing; frontend-agent formats
 * AU-local input into E.164 client-side before submit.
 */
class StoreOrderRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'booking_id' => ['required', 'integer', 'min:1'],

            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.email' => ['required', 'email', 'max:255'],
            // E.164 only (e.g. `+61491570156`) — see this class's docblock.
            // frontend-agent formats AU-local input into this shape
            // client-side before submit; this is the strict server-side
            // enforcement, no reformatting/guessing here.
            'customer.mobile' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+[1-9]\d{6,14}$/'],

            'address' => ['required', 'array'],
            'address.suburb_id' => ['required', 'integer', 'exists:suburbs,id'],
            'address.line1' => ['required', 'string', 'max:255'],
            'address.line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address.lat' => ['required', 'numeric', 'between:-90,90'],
            'address.lng' => ['required', 'numeric', 'between:-180,180'],
            'address.access_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'vehicle' => ['sometimes', 'nullable', 'array'],
            'vehicle.vehicle_id' => ['sometimes', 'nullable', 'integer', 'exists:vehicles,id'],
            'vehicle.rego' => ['sometimes', 'nullable', 'string', 'max:20'],
            'vehicle.state' => ['sometimes', 'nullable', 'string', 'max:10'],
            'vehicle.make' => ['sometimes', 'nullable', 'string', 'max:60'],
            'vehicle.model' => ['sometimes', 'nullable', 'string', 'max:60'],
            'vehicle.colour' => ['sometimes', 'nullable', 'string', 'max:40'],
            'vehicle.year' => ['sometimes', 'nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'vehicle.wheels' => ['sometimes', 'nullable', 'array', 'max:5'],
            'vehicle.wheels.*' => ['string', 'distinct', 'in:FL,FR,RL,RR,SPARE'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'newsletter_opt_in' => ['sometimes', 'boolean'],
        ];
    }
}
