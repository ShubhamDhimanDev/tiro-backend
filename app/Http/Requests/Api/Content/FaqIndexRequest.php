<?php

namespace App\Http\Requests\Api\Content;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/content/faqs` query params — both `category`/`content_page_id`
 * optional, combinable (AND'd). Deliberately no `exists:content_pages,id`
 * rule on `content_page_id`: an unmatched filter (unknown category, or a
 * `content_page_id` with no FAQs, including one that doesn't exist at all)
 * is a normal `200` with `data: []` per the Phase 6 task brief, not a
 * validation error.
 */
class FaqIndexRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string'],
            'content_page_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
