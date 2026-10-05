<?php

namespace App\Http\Requests\Admin\Promotions;

use App\Models\OrderLineItem;
use App\Models\PriceGuaranteeClaim;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /admin/price-guarantee-claims/{claim}/approve` body — see
 * docs/architecture/02-api-contract.md's "Price-guarantee claim review"
 * section. `approved_discount_amount` is validated against the matched
 * line's own `unit_price × quantity` here — mandatory, not optional, per
 * docs/architecture/05-promotions-pricing.md's security note: nothing else
 * prevents an admin creating a negative order total.
 *
 * For a post-purchase claim (`order_id` present), the matched line is the
 * order's own `OrderLineItem` for `tyre_variant_id`. For a pre-purchase
 * claim (`order_id` null) there is no persisted cart/order line to check
 * against — this project has no server-side `Cart` entity — so the cap
 * falls back to the tyre variant's own `base_price` (i.e. a single unit).
 * This is a genuine interpretation gap the docs don't resolve explicitly
 * (the cap is described purely in terms of "the matched order/cart line"),
 * flagged in the Phase 5 handback rather than silently assumed.
 */
class ApprovePriceGuaranteeClaimRequest extends FormRequest
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
        return [
            'approved_discount_amount' => ['required', 'integer', 'min:1'],
            'admin_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
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

                /** @var PriceGuaranteeClaim $claim */
                $claim = $this->route('priceGuaranteeClaim');
                $amount = (int) $this->input('approved_discount_amount');

                $cap = $this->matchedLineCap($claim);

                if ($amount > $cap) {
                    $validator->errors()->add(
                        'approved_discount_amount',
                        __('The approved discount cannot exceed the matched line value of :cap cents.', ['cap' => $cap]),
                    );
                }
            },
        ];
    }

    private function matchedLineCap(PriceGuaranteeClaim $claim): int
    {
        if ($claim->order_id !== null) {
            $lineItem = OrderLineItem::query()
                ->where('order_id', $claim->order_id)
                ->where('tyre_variant_id', $claim->tyre_variant_id)
                ->first();

            return $lineItem === null ? 0 : $lineItem->unit_price * $lineItem->quantity;
        }

        return $claim->tyreVariant->base_price;
    }
}
