<?php

namespace App\Http\Requests\Admin\Promotions;

use App\Enums\PromotionEligibilityScope;
use App\Enums\TyreCategory;
use App\Models\PromotionEligibility;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /admin/promotions/{promotion}/eligibilities` body. `scope_id` is
 * validated against a different table (or fixed vocabulary, for `category`)
 * depending on `scope` — see {@see PromotionEligibility}'s
 * docblock for why `scope_id` is a string column rather than a plain
 * integer FK.
 */
class PromotionEligibilityRequest extends FormRequest
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
        $scope = PromotionEligibilityScope::tryFrom((string) $this->input('scope'));

        return [
            'scope' => ['required', Rule::enum(PromotionEligibilityScope::class)],
            'scope_id' => [
                'required',
                'string',
                match ($scope) {
                    PromotionEligibilityScope::Brand => Rule::exists('brands', 'id'),
                    PromotionEligibilityScope::TyreModel => Rule::exists('tyre_models', 'id'),
                    PromotionEligibilityScope::TyreVariant => Rule::exists('tyre_variants', 'id'),
                    PromotionEligibilityScope::Category => Rule::in(array_map(fn (TyreCategory $c) => $c->value, TyreCategory::cases())),
                    // `scope` itself is invalid/missing — its own `required`/
                    // `Rule::enum` rule above already fails the request; this
                    // arm just needs to not blow up building the rule set.
                    null => 'string',
                },
            ],
            'service_zone_id' => ['nullable', Rule::exists('service_zones', 'id')],
        ];
    }
}
