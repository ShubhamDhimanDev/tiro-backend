<?php

namespace App\Services\Catalogue;

use App\Models\TyreVariant;
use App\Services\Commerce\CartItemInput;
use App\Services\Commerce\PricingService;

/**
 * The per-tyre price for buying 1 to 5 of a tyre, taken from the real
 * {@see PricingService} (auto-applied promotions included; promo codes,
 * flexible discount and service fee excluded) so the storefront table can
 * never disagree with `POST /api/v1/cart/calculate`.
 */
class TyrePriceLadderBuilder
{
    public const QUANTITIES = [1, 2, 3, 4, 5];

    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @return array{tyre_variant_id: int, list_price: int, four_for_three: bool, currency: string, ladder: list<array{quantity: int, unit_price: int, total: int, discount_total: int}>}
     */
    public function build(TyreVariant $variant, ?int $zoneId, bool $fourForThree): array
    {
        $ladder = [];

        foreach (self::QUANTITIES as $quantity) {
            $result = $this->pricing->priceItems(collect([new CartItemInput($variant->id, $quantity)]), $zoneId);
            $total = $result->subtotal - $result->discountTotal;

            $ladder[] = [
                'quantity' => $quantity,
                'unit_price' => (int) round($total / $quantity),
                'total' => $total,
                'discount_total' => $result->discountTotal,
            ];
        }

        return [
            'tyre_variant_id' => $variant->id,
            'list_price' => $variant->base_price,
            'four_for_three' => $fourForThree,
            'currency' => 'AUD',
            'ladder' => $ladder,
        ];
    }
}
