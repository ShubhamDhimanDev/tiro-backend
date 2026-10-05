<?php

namespace App\Http\Requests\Admin\Promotions;

use App\Enums\PromotionType;
use App\Enums\Status;
use App\Models\Promotion;
use App\Rules\NoPlaceholderTokens;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see Promotion} campaigns —
 * gated on `promotions.manage`, per
 * docs/architecture/07-admin-auth-permissions.md §3.2 and
 * docs/architecture/05-promotions-pricing.md's RBAC decision (Ecommerce or
 * Super Admin, not Customer Support/Operations).
 *
 * `value` is capped at 100 for `percentage` type (a >100% discount is never
 * meaningful) — no equivalent cap for `fixed`/`bundle`/`buy_x_get_y`/
 * `four_for_three`, where `value` is cents and any positive amount is
 * plausible. Eligibility rows (scope/scope_id/service_zone_id) are managed
 * separately via {@see PromotionEligibilityRequest} once the campaign
 * exists, mirroring `ServiceZoneRequest`/`ServiceZoneSuburbController`'s
 * "save the parent first, then attach children" pattern.
 */
class PromotionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('promotions.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = PromotionType::tryFrom((string) $this->input('type'));
        $isPercentage = $type === PromotionType::Percentage;
        // `bundle`/`buy_x_get_y`/`four_for_three` don't read `value` at all
        // (see `PromotionType`'s docblock and
        // `PromotionEvaluationService::computeGroupedDiscount()`) — the
        // column is still `NOT NULL`, but `0` is the correct "unused" input
        // for these types, matching `PromotionFactory::fourForThree()`'s own
        // `'value' => 0` convention, not an arbitrary positive placeholder.
        $isGrouped = in_array($type, [PromotionType::Bundle, PromotionType::BuyXGetY, PromotionType::FourForThree], true);

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(PromotionType::class)],
            'value' => ['required', 'integer', $isGrouped ? 'min:0' : 'min:1', $isPercentage ? 'max:100' : 'max:2147483647'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'stock_limit' => ['nullable', 'integer', 'min:1'],
            'stackable' => ['required', 'boolean'],
            // Phase 6a (all optional): typed promo code + public offer copy.
            'code' => ['nullable', 'string', 'max:40', 'alpha_dash', Rule::unique('promotions', 'code')->ignore($this->route('promotion'))],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('promotions', 'slug')->ignore($this->route('promotion'))],
            'title' => ['nullable', 'string', 'max:255', new NoPlaceholderTokens],
            'summary' => ['nullable', 'string', 'max:1000', new NoPlaceholderTokens],
            'badge_text' => ['nullable', 'string', 'max:60'],
            'discount_description' => ['nullable', 'string', 'max:255', new NoPlaceholderTokens],
            'terms' => ['nullable', 'string', 'max:5000', new NoPlaceholderTokens],
            'image_path' => ['nullable', 'string', 'max:2048'],
            'is_public' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.max' => 'A percentage discount cannot exceed 100.',
            'ends_at.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
