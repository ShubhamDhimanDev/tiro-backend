<?php

namespace App\Services\Promotions;

/**
 * The outcome of {@see PromotionEvaluationService::evaluate()} against one
 * cart/booking's pooled units.
 */
final class PromotionEvaluationResult
{
    /**
     * @param  list<AppliedPromotion>  $applied  every promotion that survived
     *                                           mutual-exclusivity/stacking resolution, in application order
     *                                           (highest total discount first, id tie-break)
     * @param  array<int, int>  $lineDiscounts  line index => total discount
     *                                          amount (cents) across every applied promotion touching that line
     * @param  array<int, AppliedPromotion>  $linePrimaryPromotion  line index =>
     *                                                              the single applied promotion contributing the most discount to
     *                                                              that line (for the `applied_promotion` per-line response field) —
     *                                                              absent for a line with no applied promotion
     * @param  array{code: string, message: string}|null  $promoError  set only when a promo code was
     *                                                                 supplied and did not end up applied
     */
    public function __construct(
        public readonly array $applied,
        public readonly array $lineDiscounts,
        public readonly array $linePrimaryPromotion,
        public readonly int $discountTotal,
        public readonly ?array $promoError = null,
    ) {}

    /**
     * @param  array{code: string, message: string}|null  $promoError
     */
    public function withPromoError(?array $promoError): self
    {
        return new self($this->applied, $this->lineDiscounts, $this->linePrimaryPromotion, $this->discountTotal, $promoError);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function appliedPromotionsSummary(): array
    {
        return array_map(fn (AppliedPromotion $applied): array => $applied->toSummaryArray(), $this->applied);
    }

    public function discountForLine(int $lineIndex): int
    {
        return $this->lineDiscounts[$lineIndex] ?? 0;
    }

    /**
     * @return array{id: int, name: string, type: string}|null
     */
    public function primaryPromotionForLine(int $lineIndex): ?array
    {
        $applied = $this->linePrimaryPromotion[$lineIndex] ?? null;

        if ($applied === null) {
            return null;
        }

        return [
            'id' => $applied->promotion->id,
            'name' => $applied->promotion->name,
            'type' => $applied->promotion->type->value,
        ];
    }
}
