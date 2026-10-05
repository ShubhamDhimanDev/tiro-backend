<?php

namespace App\Http\Requests\Api\Catalogue;

use App\Http\Requests\Concerns\TyreFilterRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/tyres/facets` query params: the size (non-staggered) and the
 * broad scope filters (`category`, `tyre_type`). The option lists returned
 * are computed over this scope only.
 */
class TyreFacetsRequest extends FormRequest
{
    use TyreFilterRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $filters = $this->tyreFilterRules();

        return [
            'width' => ['sometimes', 'integer', 'between:100,400'],
            'profile' => ['sometimes', 'integer', 'between:20,100'],
            'rim_diameter' => ['sometimes', 'integer', 'between:10,24'],
            'category' => $filters['category'],
            'tyre_type' => $filters['tyre_type'],
        ];
    }
}
