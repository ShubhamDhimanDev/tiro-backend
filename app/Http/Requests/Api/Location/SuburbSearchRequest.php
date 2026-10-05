<?php

namespace App\Http\Requests\Api\Location;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/suburbs/search` query params: `q` (2 to 60 chars, a suburb
 * name prefix or a postcode prefix) and an optional `limit`.
 */
class SuburbSearchRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:60'],
            'limit' => ['sometimes', 'integer', 'between:1,15'],
        ];
    }
}
