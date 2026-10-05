<?php

namespace App\Http\Requests\Api\Catalogue;

use App\Http\Requests\Concerns\TyreFilterRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/tyres` query params — see docs/architecture/02-api-contract.md.
 * Only type/format/range are validated here; a well-formed combination that
 * simply matches nothing is a normal `200` with `data: []`, decided in the
 * controller, not here.
 */
class TyreIndexRequest extends FormRequest
{
    use TyreFilterRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->tyreFilterRules(),
            ...$this->tyreExtraFilterRules(),
            'width' => ['sometimes', 'integer', 'between:100,400'],
            'profile' => ['sometimes', 'integer', 'between:20,100'],
            'rim_diameter' => ['sometimes', 'integer', 'between:10,24'],

            // The contract's documented value is the literal string
            // "staggered=true" — Laravel's `boolean` rule only accepts
            // true/false/1/0/"1"/"0", not the strings "true"/"false", so
            // it's spelled out explicitly here instead.
            'staggered' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'front_width' => ['required_if:staggered,1,true', 'integer', 'between:100,400'],
            'front_profile' => ['required_if:staggered,1,true', 'integer', 'between:20,100'],
            'front_rim_diameter' => ['required_if:staggered,1,true', 'integer', 'between:10,24'],
            'rear_width' => ['required_if:staggered,1,true', 'integer', 'between:100,400'],
            'rear_profile' => ['required_if:staggered,1,true', 'integer', 'between:20,100'],
            'rear_rim_diameter' => ['required_if:staggered,1,true', 'integer', 'between:10,24'],

            'zone' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc', 'name_asc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'front_page' => ['sometimes', 'integer', 'min:1'],
            'rear_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
