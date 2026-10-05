<?php

namespace App\Http\Requests\Admin\Content;

use App\Enums\PageStatus;
use App\Models\Faq;
use App\Rules\NoPlaceholderTokens;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see Faq} — both global
 * (`content_page_id = null`) and page-scoped rows share this one request,
 * gated on `content.manage` (same tier as {@see ContentPageRequest}).
 *
 * `category` is deliberately free-text (`nullable`, no `Rule::in()`), not a
 * closed enum — see the model's docblock: `"pdp"` is a reserved value the
 * PDP's shared FAQ block filters on, but the admin can create any other
 * category name.
 */
class FaqRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('content.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:65535', new NoPlaceholderTokens],
            'answer' => ['required', 'string', new NoPlaceholderTokens],
            'category' => ['nullable', 'string', 'max:255'],
            'content_page_id' => ['nullable', Rule::exists('content_pages', 'id')],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32767'],
            'status' => ['required', Rule::enum(PageStatus::class)],
        ];
    }
}
