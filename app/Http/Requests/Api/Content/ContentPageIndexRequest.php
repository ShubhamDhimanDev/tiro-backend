<?php

namespace App\Http\Requests\Api\Content;

use App\Enums\ContentPageType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/content/pages` query params. `type` is required — a 422 if
 * missing/unrecognized, per the Phase 6 task brief (different `type`s are
 * separate URL namespaces on frontend/, so an unscoped "all types" listing
 * isn't a meaningful request). `category` optional, filters within that
 * type; an unmatched category is a normal `200` with `data: []`, decided in
 * the controller, not here.
 */
class ContentPageIndexRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ContentPageType::class)],
            'category' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
