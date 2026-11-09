<?php

namespace App\Services\Promotions;

use App\Enums\PromotionRedemptionStatus;
use App\Enums\PromotionType;
use App\Enums\Status;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\TyreVariant;
use App\Services\Commerce\CartItemInput;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The promotion evaluation algorithm — see
 * docs/architecture/05-promotions-pricing.md's "Promotion evaluation
 * algorithm" and "4-for-3 mechanics" sections. Implemented exactly as
 * specified there, not an equally-plausible alternative:
 *
 * 1. Find every `ACTIVE` promotion whose eligibility matches the pooled
 *    cart/booking units, whose zone (if scoped) matches, whose date window
 *    covers today, whose `usage_count < usage_limit` (if set), and whose
 *    stock allocation isn't exhausted (if `stock_limit` is set).
 * 2. Compute each candidate's total discount across the full set of units
 *    it matches (`percentage`/`fixed`: per-unit discount × matched
 *    quantity; `four_for_three`/`bundle`/`buy_x_get_y`: the pooled
 *    sort-descending-then-group-of-4 algorithm — see this class's
 *    "bundle`/`buy_x_get_y` share the exact `four_for_three` algorithm"
 *    note on {@see PromotionType}).
 * 3. Sort candidates by total discount descending, `Promotion.id` ascending
 *    as the tie-break.
 * 4. Walk the sorted list, applying each promotion and marking its matched
 *    units as claimed; a later candidate is skipped if its matched-unit set
 *    overlaps an already-claimed unit, unless it is `stackable`.
 *
 * `usage_limit`/`usage_count` (a simple unbounded counter, incremented only
 * at webhook order-confirmation time) and `stock_limit` (the tighter,
 * hold-with-TTL-protected "first N at this price" cap via
 * {@see PromotionRedemption}) are two distinct caps checked together here,
 * not the same concept under two names.
 *
 * `$bookingId` makes the stock-limit gate "hold-aware": when evaluating
 * against an already-created `Booking` (`cart/calculate` mode 2, the
 * `POST /api/v1/orders` recompute, and the webhook confirmation
 * recompute), a stock-limited promotion is only a candidate if this
 * specific booking already holds (or has a confirmed) redemption for it —
 * i.e. it already passed the lock+stock re-check at
 * `POST /api/v1/bookings` time. Without a booking yet (`$bookingId = null`
 * — `cart/calculate` mode 1's stateless preview, and the initial
 * evaluation `BookingController::store()` runs before creating the hold
 * rows), the gate falls back to the live, non-booking-scoped capacity
 * check described in step 1 above — quantity-aware (`existing consumption +
 * this candidate's own consumed quantity <= stock_limit`), not just "is
 * there any room at all", so a booking requesting more than one unit can't
 * push a near-exhausted stock_limit into oversell. This is why the
 * stock-limit check runs *after* each candidate's `consumedQuantity` is
 * known (see `evaluate()`), not as an early per-promotion filter the way
 * the `usage_limit` check is. Non-stock-limited promotions are always
 * evaluated live, regardless of `$bookingId` — matching `usage_limit`'s own
 * accepted small-race-window posture.
 */
class PromotionEvaluationService
{
    private const GROUP_SIZE = 4;

    /** Structured `promo_error.code` values — part of the public contract. */
    public const CODE_INVALID = 'promo_code_invalid';

    public const CODE_EXPIRED = 'promo_code_expired';

    public const CODE_NOT_STARTED = 'promo_code_not_started';

    public const CODE_EXHAUSTED = 'promo_code_exhausted';

    public const CODE_INELIGIBLE = 'promo_code_ineligible';

    public const CODE_NOT_COMBINABLE = 'promo_code_not_combinable';

    /**
     * @param  Collection<int, CartItemInput>  $items
     */
    public function evaluate(Collection $items, ?int $serviceZoneId, ?int $bookingId = null, ?string $promoCode = null): PromotionEvaluationResult
    {
        $promoCode = $this->normaliseCode($promoCode);

        $variantIds = $items->pluck('tyreVariantId')->unique()->values();

        /** @var EloquentCollection<int, TyreVariant> $variantsById */
        $variantsById = TyreVariant::query()
            ->with('tyreModel')
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        $units = $this->poolUnits($items, $variantsById);

        if ($units === []) {
            return new PromotionEvaluationResult([], [], [], 0, $promoCode === null ? null : self::error(self::CODE_INELIGIBLE, 'Add tyres to your cart before applying a promo code.'));
        }

        $today = now()->toDateString();

        /** @var EloquentCollection<int, Promotion> $activePromotions */
        $activePromotions = Promotion::query()
            ->where('status', Status::Active)
            ->whereDate('starts_at', '<=', $today)
            ->whereDate('ends_at', '>=', $today)
            // A promotion with a `code` is never auto-applied: it joins the
            // candidate pool only when the customer typed exactly that code.
            ->where(fn ($query) => $query
                ->whereNull('code')
                ->when($promoCode !== null, fn ($inner) => $inner->orWhere('code', $promoCode)))
            ->with('eligibilities')
            ->orderBy('id')
            ->get()
            ->filter(fn (Promotion $promotion): bool => $this->passesUsageLimitGate($promotion));

        $candidates = [];

        foreach ($activePromotions as $promotion) {
            $matchedUnitIndices = $this->matchedUnitIndices($promotion, $units, $variantsById, $serviceZoneId);

            if ($matchedUnitIndices === []) {
                continue;
            }

            [$unitDiscounts, $consumedQuantity] = $this->computeDiscount($promotion, $matchedUnitIndices, $units);
            $totalDiscount = array_sum($unitDiscounts);

            if ($totalDiscount <= 0) {
                continue;
            }

            // Stock-limit gate runs here, after $consumedQuantity is known
            // — not as an early filter — precisely so it can be
            // quantity-aware. See this class's docblock.
            if (! $this->passesStockLimitGate($promotion, $consumedQuantity, $bookingId)) {
                continue;
            }

            $candidates[] = new PromotionCandidate($promotion, $matchedUnitIndices, $unitDiscounts, $consumedQuantity, $totalDiscount);
        }

        usort($candidates, fn (PromotionCandidate $a, PromotionCandidate $b): int => $b->totalDiscount <=> $a->totalDiscount ?: $a->promotion->id <=> $b->promotion->id);

        $claimed = [];
        $applied = [];

        foreach ($candidates as $candidate) {
            $overlaps = array_intersect($candidate->matchedUnitIndices, array_keys($claimed)) !== [];

            if ($overlaps && ! $candidate->promotion->stackable) {
                continue;
            }

            foreach ($candidate->matchedUnitIndices as $unitIndex) {
                $claimed[$unitIndex] = true;
            }

            $applied[] = new AppliedPromotion($candidate->promotion, $candidate->totalDiscount, $candidate->consumedQuantity, $candidate->unitDiscounts);
        }

        $result = $this->buildResult($applied, $units);

        if ($promoCode === null) {
            return $result;
        }

        return $result->withPromoError($this->diagnosePromoCode($promoCode, $result, $units, $variantsById, $serviceZoneId));
    }

    /**
     * @return array{code: string, message: string}
     */
    public static function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * Upper-case/trim; empty becomes null ("no code supplied").
     */
    private function normaliseCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return strtoupper(trim($code));
    }

    /**
     * Why a typed code did not end up applied, or null when it did. Order
     * matters: unknown/inactive, date window, caps, cart eligibility, and
     * finally "eligible but lost the mutual-exclusivity resolution".
     *
     * @param  list<PromotionUnit>  $units
     * @param  EloquentCollection<int, TyreVariant>  $variantsById
     * @return array{code: string, message: string}|null
     */
    private function diagnosePromoCode(string $code, PromotionEvaluationResult $result, array $units, EloquentCollection $variantsById, ?int $serviceZoneId): ?array
    {
        $promotion = Promotion::query()->with('eligibilities')->where('code', $code)->first();

        if ($promotion === null || $promotion->status !== Status::Active) {
            return self::error(self::CODE_INVALID, "We don't recognise that promo code. Check it and try again.");
        }

        $today = now()->startOfDay();

        if ($promotion->starts_at->startOfDay()->greaterThan($today)) {
            return self::error(self::CODE_NOT_STARTED, 'That promo code is not active yet.');
        }

        if ($promotion->ends_at->endOfDay()->lessThan(now())) {
            return self::error(self::CODE_EXPIRED, 'That promo code has expired.');
        }

        $exhausted = ! $this->passesUsageLimitGate($promotion)
            || ($promotion->stock_limit !== null && $promotion->usage_count + (int) PromotionRedemption::query()
                ->where('promotion_id', $promotion->id)
                ->whereIn('status', [PromotionRedemptionStatus::Held, PromotionRedemptionStatus::Confirmed])
                ->sum('quantity') >= $promotion->stock_limit);

        if ($exhausted) {
            return self::error(self::CODE_EXHAUSTED, 'That promo code has reached its redemption limit.');
        }

        foreach ($result->applied as $applied) {
            if ($applied->promotion->id === $promotion->id) {
                return null;
            }
        }

        if ($this->matchedUnitIndices($promotion, $units, $variantsById, $serviceZoneId) === []) {
            return self::error(self::CODE_INELIGIBLE, "That promo code doesn't apply to the tyres in your cart or your area.");
        }

        return self::error(self::CODE_NOT_COMBINABLE, "That promo code can't be combined with the offers already applied to your cart.");
    }

    /**
     * @param  Collection<int, CartItemInput>  $items
     * @param  EloquentCollection<int, TyreVariant>  $variantsById
     * @return list<PromotionUnit>
     */
    private function poolUnits(Collection $items, EloquentCollection $variantsById): array
    {
        $units = [];

        foreach ($items->values() as $lineIndex => $item) {
            $variant = $variantsById->get($item->tyreVariantId);

            if ($variant === null) {
                continue;
            }

            for ($i = 0; $i < $item->quantity; $i++) {
                $units[] = new PromotionUnit($lineIndex, $item->tyreVariantId, $variant->base_price);
            }
        }

        return $units;
    }

    /**
     * `usage_limit`/`usage_count` — a simple, deliberately looser total-
     * redemptions counter (no hold, no quantity-awareness; see this class's
     * docblock and docs/architecture/05-promotions-pricing.md). Checked as
     * an early per-promotion filter since it doesn't depend on how many
     * units *this* candidate would consume.
     */
    private function passesUsageLimitGate(Promotion $promotion): bool
    {
        return $promotion->usage_limit === null || $promotion->usage_count < $promotion->usage_limit;
    }

    /**
     * `stock_limit` — the tighter, hold-protected "first N at this price"
     * cap. Quantity-aware: `$requestedQuantity` is this specific
     * candidate's own `consumedQuantity` (the number of stock-limited
     * slots it would claim), so a request for more than one unit against a
     * near-exhausted `stock_limit` is correctly rejected even though the
     * *existing* consumption alone is still under the limit — a boolean
     * "is there any room at all" check would let that request oversell.
     * Corrected 2026-09-22 after this exact gap was flagged in the Phase 5
     * handback and confirmed a real bug (not a case where the doc's literal
     * wording should win over its own stated intent) rather than shipped
     * silently.
     */
    private function passesStockLimitGate(Promotion $promotion, int $requestedQuantity, ?int $bookingId): bool
    {
        if ($promotion->stock_limit === null) {
            return true;
        }

        if ($bookingId !== null) {
            return PromotionRedemption::query()
                ->where('promotion_id', $promotion->id)
                ->where('booking_id', $bookingId)
                ->whereIn('status', [PromotionRedemptionStatus::Held, PromotionRedemptionStatus::Confirmed])
                ->exists();
        }

        $consumed = $promotion->usage_count + (int) PromotionRedemption::query()
            ->where('promotion_id', $promotion->id)
            ->whereIn('status', [PromotionRedemptionStatus::Held, PromotionRedemptionStatus::Confirmed])
            ->sum('quantity');

        return $consumed + $requestedQuantity <= $promotion->stock_limit;
    }

    /**
     * @param  list<PromotionUnit>  $units
     * @param  EloquentCollection<int, TyreVariant>  $variantsById
     * @return list<int>
     */
    private function matchedUnitIndices(Promotion $promotion, array $units, EloquentCollection $variantsById, ?int $serviceZoneId): array
    {
        $indices = [];

        foreach ($units as $index => $unit) {
            $variant = $variantsById->get($unit->tyreVariantId);

            if ($variant === null) {
                continue;
            }

            foreach ($promotion->eligibilities as $eligibility) {
                if ($eligibility->matchesZone($serviceZoneId) && $eligibility->matchesVariant($variant)) {
                    $indices[] = $index;

                    break;
                }
            }
        }

        return $indices;
    }

    /**
     * @param  list<int>  $matchedUnitIndices
     * @param  list<PromotionUnit>  $units
     * @return array{0: array<int, int>, 1: int}
     */
    private function computeDiscount(Promotion $promotion, array $matchedUnitIndices, array $units): array
    {
        // Matched directly over the enum-cast `$promotion->type` property —
        // exhaustive over every current case, no `default` arm (phpstan
        // would flag one as unreachable dead code here, same reasoning as
        // `CancellationPolicy::resolveFee()`'s docblock). `Bundle`/
        // `BuyXGetY` share `FourForThree`'s grouping algorithm — see
        // `PromotionType`'s docblock for why.
        return match ($promotion->type) {
            PromotionType::Percentage => $this->computePerUnitDiscount($promotion, $matchedUnitIndices, $units, isPercentage: true),
            PromotionType::Fixed => $this->computePerUnitDiscount($promotion, $matchedUnitIndices, $units, isPercentage: false),
            PromotionType::FourForThree, PromotionType::Bundle, PromotionType::BuyXGetY => $this->computeGroupedDiscount($matchedUnitIndices, $units),
        };
    }

    /**
     * `percentage`/`fixed`: a flat per-unit discount × matched quantity —
     * see docs/architecture/05-promotions-pricing.md step 2.1. Clamped to
     * the unit's own price so a single promotion alone can never discount a
     * unit below zero.
     *
     * @param  list<int>  $matchedUnitIndices
     * @param  list<PromotionUnit>  $units
     * @return array{0: array<int, int>, 1: int}
     */
    private function computePerUnitDiscount(Promotion $promotion, array $matchedUnitIndices, array $units, bool $isPercentage): array
    {
        $unitDiscounts = [];
        $quantity = 0;

        foreach ($matchedUnitIndices as $unitIndex) {
            $unit = $units[$unitIndex];

            $discount = $isPercentage
                ? (int) round($unit->unitPrice * $promotion->value / 100)
                : $promotion->value;

            $discount = min(max($discount, 0), $unit->unitPrice);

            if ($discount > 0) {
                $unitDiscounts[$unitIndex] = $discount;
                $quantity++;
            }
        }

        return [$unitDiscounts, $quantity];
    }

    /**
     * 4-for-3 mechanics, implemented exactly per
     * docs/architecture/05-promotions-pricing.md: pool the matched units,
     * sort by unit price descending, partition into consecutive groups of
     * 4, and for each **complete** group discount the last (lowest-priced)
     * unit 100%. A trailing partial group gets no discount.
     *
     * @param  list<int>  $matchedUnitIndices
     * @param  list<PromotionUnit>  $units
     * @return array{0: array<int, int>, 1: int}
     */
    private function computeGroupedDiscount(array $matchedUnitIndices, array $units): array
    {
        $sorted = $matchedUnitIndices;

        usort($sorted, fn (int $a, int $b): int => $units[$b]->unitPrice <=> $units[$a]->unitPrice);

        $completeGroups = intdiv(count($sorted), self::GROUP_SIZE);
        $unitDiscounts = [];

        for ($group = 0; $group < $completeGroups; $group++) {
            $groupIndices = array_slice($sorted, $group * self::GROUP_SIZE, self::GROUP_SIZE);
            $freeUnitIndex = $groupIndices[self::GROUP_SIZE - 1];
            $unitDiscounts[$freeUnitIndex] = $units[$freeUnitIndex]->unitPrice;
        }

        return [$unitDiscounts, $completeGroups];
    }

    /**
     * Aggregate the applied promotions' per-unit discounts into per-line
     * totals and a per-line "primary" promotion for display, clamping each
     * unit's *cumulative* discount (across every stacked promotion touching
     * it) to its own price — this is the mechanism that guarantees a
     * stacked combination can never drive a line (and therefore
     * `grand_total`) negative, per
     * docs/architecture/05-promotions-pricing.md's bar.
     *
     * @param  list<AppliedPromotion>  $applied
     * @param  list<PromotionUnit>  $units
     */
    private function buildResult(array $applied, array $units): PromotionEvaluationResult
    {
        /** @var array<int, int> $finalUnitDiscount */
        $finalUnitDiscount = [];
        /** @var array<int, array<int, int>> $lineContribution line index => promotion id => amount */
        $lineContribution = [];
        /** @var array<int, array<int, AppliedPromotion>> $promotionByLine line index => promotion id => AppliedPromotion */
        $promotionByLine = [];

        foreach ($applied as $appliedPromotion) {
            foreach ($appliedPromotion->unitDiscounts as $unitIndex => $discount) {
                if ($discount <= 0) {
                    continue;
                }

                $unit = $units[$unitIndex];
                $existing = $finalUnitDiscount[$unitIndex] ?? 0;
                $room = max(0, $unit->unitPrice - $existing);
                $actual = min($discount, $room);

                if ($actual <= 0) {
                    continue;
                }

                $finalUnitDiscount[$unitIndex] = $existing + $actual;

                $lineIndex = $unit->lineIndex;
                $promotionId = $appliedPromotion->promotion->id;

                $lineContribution[$lineIndex][$promotionId] = ($lineContribution[$lineIndex][$promotionId] ?? 0) + $actual;
                $promotionByLine[$lineIndex][$promotionId] = $appliedPromotion;
            }
        }

        $lineDiscounts = [];
        $linePrimaryPromotion = [];
        $discountTotal = 0;

        foreach ($lineContribution as $lineIndex => $byPromotion) {
            $lineTotal = array_sum($byPromotion);
            $lineDiscounts[$lineIndex] = $lineTotal;
            $discountTotal += $lineTotal;

            $bestPromotionId = null;
            $bestAmount = -1;

            foreach ($byPromotion as $promotionId => $amount) {
                if ($amount > $bestAmount || ($amount === $bestAmount && $bestPromotionId !== null && $promotionId < $bestPromotionId)) {
                    $bestAmount = $amount;
                    $bestPromotionId = $promotionId;
                }
            }

            if ($bestPromotionId !== null) {
                $linePrimaryPromotion[$lineIndex] = $promotionByLine[$lineIndex][$bestPromotionId];
            }
        }

        return new PromotionEvaluationResult($applied, $lineDiscounts, $linePrimaryPromotion, $discountTotal);
    }
}
