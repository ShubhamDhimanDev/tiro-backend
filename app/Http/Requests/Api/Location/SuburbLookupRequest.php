<?php

namespace App\Http\Requests\Api\Location;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/suburbs` query params — see
 * docs/architecture/02-api-contract.md. Unlike
 * `POST /api/v1/serviceability`'s postcode-*or*-suburb lookup (which only
 * needs "any candidate resolves to the same zone"), this endpoint must
 * resolve to a specific `Suburb` row, so both `postcode` and `name` are
 * required together — the conjunction is what narrows a search down to
 * (usually) exactly one row.
 */
class SuburbLookupRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'postcode' => ['required', 'string', 'regex:/^\d{4}$/'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }
}
