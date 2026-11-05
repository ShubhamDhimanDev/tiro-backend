<?php

namespace App\Http\Requests\Admin\Promotions;

use App\Enums\Status;
use App\Models\SizePriceBand;
use App\Services\Commerce\SizePricingService;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for a {@see SizePriceBand} and its tiers. Gated
 * on `promotions.manage` like every other pricing lever. `tiers[].total_price`
 * is in whole cents.
 *
 * Tiers must run 1..N with no gaps (N up to {@see SizePricingService::MAX_TYRES}),
 * and a bigger order may never cost less than a smaller one. Two active bands
 * may not cover the same rim diameter, because "which price wins" would
 * otherwise depend on query order.
 */
class SizePriceBandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('promotions.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'rim_min' => ['required', 'integer', 'between:10,30'],
            'rim_max' => ['required', 'integer', 'between:10,30', 'gte:rim_min'],
            'status' => ['required', Rule::enum(Status::class)],
            'tiers' => ['required', 'array', 'min:1', 'max:'.SizePricingService::MAX_TYRES],
            'tiers.*.quantity' => ['required', 'integer', 'distinct', 'between:1,'.SizePricingService::MAX_TYRES],
            'tiers.*.total_price' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rim_max.gte' => 'The largest rim size must be at least the smallest.',
            'tiers.*.total_price.min' => 'Enter a price for every tyre count.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var array<int, int> $totals quantity => cents */
                $totals = [];

                foreach ((array) $this->input('tiers') as $tier) {
                    $totals[(int) $tier['quantity']] = (int) $tier['total_price'];
                }

                ksort($totals);

                if (array_keys($totals) !== range(1, count($totals))) {
                    $validator->errors()->add('tiers', 'Price 1 tyre, then 2, then 3… with no tyre count skipped.');

                    return;
                }

                $previous = 0;

                foreach ($totals as $quantity => $total) {
                    if ($total < $previous) {
                        $validator->errors()->add('tiers', "{$quantity} tyres cannot cost less than ".($quantity - 1).' tyres.');

                        return;
                    }

                    $previous = $total;
                }

                if ($this->input('status') !== Status::Active->value) {
                    return;
                }

                /** @var SizePriceBand|null $band */
                $band = $this->route('sizePriceBand');

                $overlap = SizePriceBand::query()
                    ->where('status', Status::Active)
                    ->where('rim_min', '<=', (int) $this->input('rim_max'))
                    ->where('rim_max', '>=', (int) $this->input('rim_min'))
                    ->when($band, fn ($query) => $query->whereKeyNot($band->id))
                    ->first();

                if ($overlap !== null) {
                    $validator->errors()->add('rim_min', "These rim sizes overlap the active band \"{$overlap->name}\" ({$overlap->rim_min}–{$overlap->rim_max}\"). Adjust one of them or deactivate it first.");
                }
            },
        ];
    }
}
