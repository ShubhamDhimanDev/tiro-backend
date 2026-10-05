<?php

namespace App\Services\Commerce;

use App\Models\PriceGuaranteeClaim;
use App\Models\Promotion;

final class PricingResult
{
    /**
     * @param  list<PricingLine>  $lines
     * @param  list<array{id: int, name: string, type: string, discount_amount: int}>  $appliedPromotions  cart-level
     *                                                                                                     summary of every applied {@see Promotion} — see
     *                                                                                                     docs/architecture/02-api-contract.md's Phase 5 `cart/calculate`
     *                                                                                                     addition. Always present (empty array when nothing applies), same
     *                                                                                                     "field always present, value reflects state" convention as
     *                                                                                                     `manage_token_issued`.
     * @param  array{code: string, message: string}|null  $promoError
     * @param  list<int>  $appliedPriceGuaranteeClaimIds  internal only, never serialized — the
     *                                                    {@see PriceGuaranteeClaim} row ids whose
     *                                                    `approved_discount_amount` contributed to this result, so
     *                                                    `POST /api/v1/orders` can stamp `redeemed_at`/`order_id` on them
     *                                                    at order-creation time without a second lookup.
     */
    public function __construct(
        public readonly int $subtotal,
        public readonly int $discountTotal,
        public readonly int $taxTotal,
        public readonly int $serviceFeeTotal,
        public readonly int $grandTotal,
        public readonly string $currency,
        public readonly array $lines,
        public readonly array $appliedPromotions = [],
        public readonly array $appliedPriceGuaranteeClaimIds = [],
        public readonly int $flexibleDiscount = 0,
        public readonly string $flexibleLabel = 'Flexible booking discount',
        public readonly ?array $promoError = null,
    ) {}

    /**
     * `discount_total` = per-line promotion/claim discounts + `flexible_discount.amount`.
     * `discount_lines` is the flat, display-ready list of every labelled
     * discount (`promotion`, `flexible`) so the UI never has to reconstruct it.
     * `promo_error` is null unless a `promo_code` was supplied and not applied.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $discountLines = array_map(fn (array $applied): array => [
            'type' => 'promotion',
            'label' => $applied['label'] ?? $applied['name'],
            'amount' => $applied['amount'] ?? $applied['discount_amount'],
        ], $this->appliedPromotions);

        if ($this->flexibleDiscount > 0) {
            $discountLines[] = ['type' => 'flexible', 'label' => $this->flexibleLabel, 'amount' => $this->flexibleDiscount];
        }

        return [
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discountTotal,
            'tax_total' => $this->taxTotal,
            'service_fee_total' => $this->serviceFeeTotal,
            'grand_total' => $this->grandTotal,
            'currency' => $this->currency,
            'lines' => array_map(fn (PricingLine $line): array => $line->toArray(), $this->lines),
            'applied_promotions' => $this->appliedPromotions,
            'flexible_discount' => $this->flexibleDiscount > 0
                ? ['label' => $this->flexibleLabel, 'amount' => $this->flexibleDiscount]
                : null,
            'discount_lines' => $discountLines,
            'promo_error' => $this->promoError,
        ];
    }
}
