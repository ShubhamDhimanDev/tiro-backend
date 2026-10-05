<?php

namespace App\Services\Commerce;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PriceGuaranteeClaim;
use App\Models\PriceRule;
use App\Models\TyreVariant;
use App\Services\Bookings\FlexibleBookingPolicy;
use App\Services\Promotions\PromotionEvaluationResult;
use App\Services\Promotions\PromotionEvaluationService;
use Illuminate\Support\Collection;

/**
 * The single pricing computation both `POST /api/v1/cart/calculate` (both
 * input modes) and `POST /api/v1/orders`'s server-side recompute call —
 * deliberately one code path, not two independent implementations that
 * could drift, per docs/architecture/02-api-contract.md's "Cart, Checkout &
 * Payment endpoints" section.
 *
 * Phase 5 makes `discount_total`/`service_fee_total` real computations
 * (previously always-0 placeholders): `discount_total` from
 * {@see PromotionEvaluationService} (the mutually-exclusive-by-default
 * `Promotion` pool) plus any additive, separately-tracked
 * {@see PriceGuaranteeClaim} discount; `service_fee_total` from
 * {@see PriceRule} (service-fee-only per open decision #7 — see
 * docs/architecture/06-open-decisions.md). No discount is ever accepted as
 * client input — always server-computed from DB state against cart
 * contents and zone, per this project's standing "never trust client-sent
 * amounts" posture.
 */
class PricingService
{
    /**
     * GST-inclusive extraction divisor — see docs/architecture/01-data-model.md's
     * "Money & tax convention" section: a GST-inclusive price equals the
     * ex-GST price × 1.1, so GST = inclusive price ÷ 11.
     */
    private const GST_DIVISOR = 11;

    public function __construct(
        private readonly PromotionEvaluationService $promotions,
        private readonly FlexibleBookingPolicy $flexiblePolicy,
    ) {}

    /**
     * Mode 1 — raw `{zone_id, items}`, pre-booking. `$items` may legitimately
     * be empty (an empty cart prices to all-zero totals), same permissive
     * posture as `App\Http\Requests\Concerns\ValidatesBookingCart`.
     *
     * `$promoCode`: an optional customer-typed code; an unusable one never
     * fails the pricing, it just yields `promoError` on the result.
     * `$flexible`: apply the flexible-booking discount (if the policy is on).
     *
     * @param  Collection<int, CartItemInput>  $items
     */
    public function priceItems(Collection $items, ?int $serviceZoneId = null, ?Customer $customer = null, ?int $bookingId = null, ?string $promoCode = null, bool $flexible = false): PricingResult
    {
        if ($items->isEmpty()) {
            return new PricingResult(0, 0, 0, 0, 0, 'AUD', [], promoError: trim((string) $promoCode) === ''
                ? null
                : PromotionEvaluationService::error(PromotionEvaluationService::CODE_INELIGIBLE, 'Add tyres to your cart before applying a promo code.'));
        }

        $variantsById = TyreVariant::query()
            ->whereIn('id', $items->pluck('tyreVariantId')->unique())
            ->get()
            ->keyBy('id');

        $evaluation = $this->promotions->evaluate($items, $serviceZoneId, $bookingId, $promoCode);

        $lines = [];
        $subtotal = 0;
        $discountTotal = 0;
        $appliedClaimIds = [];

        foreach ($items->values() as $lineIndex => $item) {
            /** @var TyreVariant $variant */
            $variant = $variantsById->get($item->tyreVariantId)
                ?? TyreVariant::query()->findOrFail($item->tyreVariantId);

            $lineValue = $variant->base_price * $item->quantity;
            $promoDiscount = min($evaluation->discountForLine($lineIndex), $lineValue);

            $claimDiscount = 0;
            $claimId = null;

            if ($customer !== null) {
                $claim = PriceGuaranteeClaim::query()
                    ->usable($customer->id, $item->tyreVariantId)
                    ->orderBy('id')
                    ->first();

                if ($claim !== null) {
                    $remaining = max(0, $lineValue - $promoDiscount);
                    $claimDiscount = min((int) $claim->approved_discount_amount, $remaining);
                    $claimId = $claim->id;
                }
            }

            $discountAmount = $promoDiscount + $claimDiscount;

            if ($claimId !== null && $claimDiscount > 0) {
                $appliedClaimIds[] = $claimId;
            }

            $lineTotal = $lineValue - $discountAmount;
            $taxAmount = (int) round($variant->base_price / self::GST_DIVISOR);

            $lines[] = new PricingLine(
                tyreVariantId: $item->tyreVariantId,
                quantity: $item->quantity,
                unitPrice: $variant->base_price,
                promotionalPrice: null,
                discountAmount: $discountAmount,
                taxAmount: $taxAmount,
                lineTotal: $lineTotal,
                appliedPromotion: $evaluation->primaryPromotionForLine($lineIndex),
            );

            $subtotal += $lineValue;
            $discountTotal += $discountAmount;
        }

        // Order-level (not per line), applied after promotions/claims and
        // clamped so the total can never go negative.
        $flexibleDiscount = ($flexible && $this->flexiblePolicy->isAvailable())
            ? min($this->flexiblePolicy->discountCents(), max(0, $subtotal - $discountTotal))
            : 0;
        $discountTotal += $flexibleDiscount;

        $serviceFeeTotal = PriceRule::feeForZone($serviceZoneId, max(0, $subtotal - $discountTotal));
        $taxTotal = (int) round(($subtotal - $discountTotal + $serviceFeeTotal) / self::GST_DIVISOR);
        $grandTotal = max(0, $subtotal - $discountTotal + $serviceFeeTotal);

        return new PricingResult(
            $subtotal,
            $discountTotal,
            $taxTotal,
            $serviceFeeTotal,
            $grandTotal,
            'AUD',
            $lines,
            $evaluation->appliedPromotionsSummary(),
            array_values(array_unique($appliedClaimIds)),
            $flexibleDiscount,
            $this->flexiblePolicy->lineLabel(),
            $evaluation->promoError,
        );
    }

    /**
     * Mode 2 — checkout, against an already-created `Booking`. Zone and
     * items are derived from the booking (`service_zone_id`,
     * `BookingLineItem` rows), never re-supplied by the caller. Also the
     * exact call `POST /api/v1/orders` uses for its mandatory server-side
     * pricing recompute (never trusts client-sent amounts), and the one the
     * Stripe webhook's confirmation step reuses to stamp each held
     * `PromotionRedemption`'s final `discount_amount`.
     *
     * `$customer` defaults to the booking's own linked customer when not
     * explicitly supplied — callers that already resolved the acting
     * customer from the request (guest vs. authenticated) should pass it
     * explicitly instead, since a guest checkout customer may not yet be
     * linked to the booking at all.
     */
    public function priceBooking(Booking $booking, ?Customer $customer = null): PricingResult
    {
        $resolvedCustomer = $customer ?? ($booking->customer_id === null ? null : $booking->customer);

        return $this->priceItems($this->itemsFor($booking), $booking->service_zone_id, $resolvedCustomer, $booking->id, $booking->promo_code, $booking->is_flexible);
    }

    /**
     * Re-run the promotion evaluation only (no `PriceRule`/claim/tax
     * recompute needed), **not** hold-aware — the exact call
     * `BookingController::store()` uses right after creating a booking's
     * line items, before any `PromotionRedemption` hold rows exist, to
     * decide which promotions to attempt a stock hold for.
     */
    public function evaluatePromotionsForNewBooking(Booking $booking): PromotionEvaluationResult
    {
        return $this->promotions->evaluate($this->itemsFor($booking), $booking->service_zone_id, null, $booking->promo_code);
    }

    /**
     * Re-run the promotion evaluation only, hold-aware via `$booking`'s own
     * id — the webhook confirmation step reuses this to re-derive each held
     * `PromotionRedemption`'s final `discount_amount` against the booking's
     * already-decided applied-promotion set.
     */
    public function evaluatePromotionsForBooking(Booking $booking): PromotionEvaluationResult
    {
        return $this->promotions->evaluate($this->itemsFor($booking), $booking->service_zone_id, $booking->id, $booking->promo_code);
    }

    /**
     * @return Collection<int, CartItemInput>
     */
    private function itemsFor(Booking $booking): Collection
    {
        return $booking->lineItems->map(fn ($lineItem): CartItemInput => new CartItemInput(
            tyreVariantId: $lineItem->tyre_variant_id,
            quantity: $lineItem->quantity,
        ));
    }
}
