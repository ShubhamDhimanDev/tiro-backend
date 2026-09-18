<?php

namespace App\Http\Requests\Api\Catalogue;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/tyres/{slug}/availability` query params — `zone` is
 * required; omitting it is `422` per
 * docs/architecture/02-api-contract.md.
 */
class TyreAvailabilityRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'zone' => ['required', 'integer', 'min:1'],
        ];
    }
}
