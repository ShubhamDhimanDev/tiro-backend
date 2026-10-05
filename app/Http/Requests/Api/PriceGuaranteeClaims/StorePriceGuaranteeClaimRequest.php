<?php

namespace App\Http\Requests\Api\PriceGuaranteeClaims;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/price-guarantee-claims` body — see
 * docs/architecture/02-api-contract.md's "Promotions & Price-Guarantee
 * endpoints" section. `auth:customer` is required at the route level, not
 * here. `order_id`'s "must belong to the authenticated customer" check is a
 * `403`, not a validation rule, so it happens in the controller — this
 * class only validates shape/existence.
 */
class StorePriceGuaranteeClaimRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'competitor_url' => ['required', 'url', 'max:2048'],
            'competitor_price' => ['required', 'integer', 'min:1'],
            'tyre_variant_id' => ['required', 'integer', 'exists:tyre_variants,id'],
            'order_id' => ['sometimes', 'nullable', 'integer', 'exists:orders,id'],
        ];
    }
}
