<?php

namespace App\Http\Requests\Api\Catalogue;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/tyres/latest-releases` query params — sort order is fixed
 * (`tyre_model.released_at` desc, fallback `created_at`), so only
 * zone/pagination are accepted.
 */
class TyreLatestReleasesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'zone' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
