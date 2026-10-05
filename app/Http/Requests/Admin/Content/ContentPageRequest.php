<?php

namespace App\Http\Requests\Admin\Content;

use App\Enums\ContentPageType;
use App\Enums\PageStatus;
use App\Models\ContentPage;
use App\Rules\NoPlaceholderTokens;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see ContentPage} — gated on
 * `content.manage` (verified against `RolesAndPermissionsSeeder`: super_admin
 * and ecommerce hold `manage`, every other role holds `none`).
 *
 * `slug` is unique per `(type, slug)`, not globally — matches the migration's
 * own `unique(['type', 'slug'])` constraint (see that migration's docblock:
 * different types are separate URL namespaces on frontend/). `service_zone_id`
 * only means something for `location_page` and `promotion_id` only for
 * `promo_landing`, but both stay `nullable` at the validation layer
 * regardless of `type` — see the Phase 6 task brief's "surface as conditional
 * fields, not always-visible" framing: this is a UI presentation rule, not a
 * DB/validation requirement, and the columns themselves are optional linkage
 * only (see the model's docblock).
 */
class ContentPageRequest extends FormRequest
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
        /** @var ContentPage|null $contentPage */
        $contentPage = $this->route('contentPage');
        $type = $this->input('type');

        return [
            'type' => ['required', Rule::enum(ContentPageType::class)],
            'title' => ['required', 'string', 'max:255', new NoPlaceholderTokens],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('content_pages', 'slug')
                    ->where(fn ($query) => $query->where('type', $type))
                    ->ignore($contentPage),
            ],
            'excerpt' => ['nullable', 'string', 'max:65535', new NoPlaceholderTokens],
            'body' => ['required', 'string', new NoPlaceholderTokens],
            'featured_image_path' => ['nullable', 'string', 'max:2048'],
            'meta_title' => ['nullable', 'string', 'max:255', new NoPlaceholderTokens],
            'meta_description' => ['nullable', 'string', 'max:500', new NoPlaceholderTokens],
            'og_image_path' => ['nullable', 'string', 'max:2048'],
            'category' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(PageStatus::class)],
            'published_at' => ['nullable', 'date'],
            'service_zone_id' => ['nullable', Rule::exists('service_zones', 'id')],
            'promotion_id' => ['nullable', Rule::exists('promotions', 'id')],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32767'],
        ];
    }
}
