<?php

namespace App\Services\Commerce;

use App\Models\Promotion;

/**
 * One priced cart/order line — the exact shape of a `lines[]` entry in
 * `POST /api/v1/cart/calculate`'s response (see
 * docs/architecture/02-api-contract.md), and the source for the
 * `OrderLineItem` row `POST /api/v1/orders` persists per line.
 *
 * `tax_amount` is the extracted GST on `unit_price` (per unit, not scaled
 * by `quantity`) — inferred from the one concrete worked number in
 * 02-api-contract.md's cart/calculate example (`unit_price: 18900` →
 * `tax_amount: 1718`, which is `round(18900 / 11)`, not
 * `round(18900 * 4 / 11)`); the docs don't state this in prose. The
 * order-level `tax_total` is computed independently from the aggregate
 * `subtotal` via the standard formula (see `PricingService`) and is not
 * required to reconcile to the exact cent against `Σ tax_amount` — it's an
 * informational breakdown, not additive, per the "Money & tax convention"
 * section of docs/architecture/01-data-model.md.
 */
final class PricingLine
{
    /**
     * @param  array{id: int, name: string, type: string}|null  $appliedPromotion  the single {@see Promotion}
     *                                                                             contributing the most discount to this line — see
     *                                                                             docs/architecture/02-api-contract.md's Phase 5 `cart/calculate`
     *                                                                             addition. Always present in the response (never omitted), null when
     *                                                                             no promotion applies to this line.
     */
    public function __construct(
        public readonly int $tyreVariantId,
        public readonly int $quantity,
        public readonly int $unitPrice,
        public readonly ?int $promotionalPrice,
        public readonly int $discountAmount,
        public readonly int $taxAmount,
        public readonly int $lineTotal,
        public readonly ?array $appliedPromotion = null,
    ) {}

    /**
     * @return array{tyre_variant_id: int, quantity: int, unit_price: int, promotional_price: int|null, discount_amount: int, tax_amount: int, line_total: int, applied_promotion: array{id: int, name: string, type: string}|null}
     */
    public function toArray(): array
    {
        return [
            'tyre_variant_id' => $this->tyreVariantId,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'promotional_price' => $this->promotionalPrice,
            'discount_amount' => $this->discountAmount,
            'tax_amount' => $this->taxAmount,
            'line_total' => $this->lineTotal,
            'applied_promotion' => $this->appliedPromotion,
        ];
    }
}
