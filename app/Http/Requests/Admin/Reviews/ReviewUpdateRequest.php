<?php

namespace App\Http\Requests\Admin\Reviews;

use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /admin/reviews/{review}` — the moderation toggle, gated
 * `content.manage` (same tier as `App\Http\Requests\Admin\Content\FaqRequest`).
 */
class ReviewUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('content.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_hidden' => ['required', 'boolean'],
        ];
    }
}
