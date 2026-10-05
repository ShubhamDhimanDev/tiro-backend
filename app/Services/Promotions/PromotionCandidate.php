<?php

namespace App\Services\Promotions;

use App\Models\Promotion;

/**
 * An `ACTIVE`, eligible candidate promotion before mutual-exclusivity/
 * stacking resolution — step 2 of the evaluation algorithm in
 * docs/architecture/05-promotions-pricing.md. Sorted descending by
 * `totalDiscount` (id tie-break) and walked to decide which candidates
 * actually become an {@see AppliedPromotion}.
 */
final class PromotionCandidate
{
    /**
     * @param  list<int>  $matchedUnitIndices  pooled unit indices this
     *                                         promotion's eligibility rules match — also the "claimed units" set
     *                                         used for overlap/stacking resolution
     * @param  array<int, int>  $unitDiscounts  unit index => discount amount
     *                                          (cents) this candidate would grant that unit
     */
    public function __construct(
        public readonly Promotion $promotion,
        public readonly array $matchedUnitIndices,
        public readonly array $unitDiscounts,
        public readonly int $consumedQuantity,
        public readonly int $totalDiscount,
    ) {}
}
