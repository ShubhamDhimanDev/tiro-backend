<?php

namespace App\Services\Promotions;

use App\Models\Promotion;

/**
 * One promotion that survived the mutual-exclusivity/stacking resolution in
 * {@see PromotionEvaluationService::evaluate()} and is actually applied to
 * the cart/booking it was evaluated against.
 */
final class AppliedPromotion
{
    /**
     * @param  array<int, int>  $unitDiscounts  unit index (into the pooled
     *                                          unit list) => discount amount (cents) this promotion grants
     *                                          that unit. Sparse — only entries for units this promotion
     *                                          actually discounts.
     */
    public function __construct(
        public readonly Promotion $promotion,
        public readonly int $totalDiscount,
        public readonly int $consumedQuantity,
        public readonly array $unitDiscounts,
    ) {}

    /**
     * `label`/`amount` are the display line (label falls back to the
     * promotion's name); `source` is `code` when the customer's typed code
     * triggered it, `auto` otherwise; `code` is only ever non-null for a
     * coded promotion (which by construction the customer typed).
     *
     * @return array{id: int, name: string, type: string, discount_amount: int, label: string, amount: int, source: string, code: string|null}
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->promotion->id,
            'name' => $this->promotion->name,
            'type' => $this->promotion->type->value,
            'discount_amount' => $this->totalDiscount,
            'label' => $this->promotion->title ?: $this->promotion->name,
            'amount' => $this->totalDiscount,
            'source' => $this->promotion->code !== null ? 'code' : 'auto',
            'code' => $this->promotion->code,
        ];
    }
}
