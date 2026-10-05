<?php

namespace App\Http\Requests\Admin\Promotions;

use App\Enums\CancellationFeeType;
use App\Enums\Status;
use App\Models\PriceRule;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see PriceRule} — gated on
 * `promotions.manage`, not `locations.manage`, per
 * docs/architecture/05-promotions-pricing.md's RBAC decision. `fee_amount`
 * (cents) required when `fee_type = flat`, `fee_percent` when
 * `fee_type = percent` — same conditional-required shape as
 * `CancellationPolicyRequest`, which this reuses the enum from.
 */
class PriceRuleRequest extends FormRequest
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
        $isFlat = $this->input('fee_type') === CancellationFeeType::Flat->value;

        return [
            'service_zone_id' => ['required', Rule::exists('service_zones', 'id')],
            'fee_type' => ['required', Rule::enum(CancellationFeeType::class)],
            'fee_amount' => [$isFlat ? 'required' : 'nullable', 'integer', 'min:0'],
            'fee_percent' => [$isFlat ? 'nullable' : 'required', 'integer', 'between:0,100'],
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
            'fee_amount.required' => 'A fee amount is required for flat fees.',
            'fee_percent.required' => 'A fee percentage is required for percent fees.',
        ];
    }

    /**
     * Backstops `PriceRule::feeForZone()`'s "first active row wins" lookup,
     * which is only deterministic if at most one active row exists per
     * `service_zone_id` at a time — same invariant/shape as
     * `CancellationPolicyRequest::after()`, adapted for `service_zone_id`
     * being required here (no "global default" null case).
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->input('status') !== Status::Active->value) {
                    return;
                }

                /** @var PriceRule|null $priceRule */
                $priceRule = $this->route('priceRule');

                $query = PriceRule::query()
                    ->where('status', Status::Active)
                    ->where('service_zone_id', $this->input('service_zone_id'))
                    ->when($priceRule, fn ($q) => $q->whereKeyNot($priceRule->id));

                if ($query->exists()) {
                    $validator->errors()->add(
                        'service_zone_id',
                        'This zone already has an active price rule. Deactivate it before activating another.',
                    );
                }
            },
        ];
    }
}
