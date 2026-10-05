<?php

use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\TyreVariant;
use App\Services\Commerce\CartItemInput;
use App\Services\Commerce\PricingService;
use Illuminate\Support\Collection;

/**
 * Exercises the exact worked example from
 * docs/architecture/02-api-contract.md's `POST /api/v1/cart/calculate`
 * section (`unit_price: 18900, quantity: 4` → `subtotal: 75600, tax_total:
 * 6873`) — the single concrete source of truth for this GST-inclusive
 * extraction math.
 */
beforeEach(function () {
    $this->service = app(PricingService::class);
});

it('prices an empty cart to all-zero totals', function () {
    $result = $this->service->priceItems(new Collection);

    expect($result->subtotal)->toBe(0);
    expect($result->discountTotal)->toBe(0);
    expect($result->taxTotal)->toBe(0);
    expect($result->serviceFeeTotal)->toBe(0);
    expect($result->grandTotal)->toBe(0);
    expect($result->lines)->toBe([]);
});

it('matches the documented worked example exactly', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);

    $items = collect([new CartItemInput(tyreVariantId: $variant->id, quantity: 4)]);

    $result = $this->service->priceItems($items);

    expect($result->subtotal)->toBe(75600);
    expect($result->discountTotal)->toBe(0);
    expect($result->taxTotal)->toBe(6873);
    expect($result->serviceFeeTotal)->toBe(0);
    expect($result->grandTotal)->toBe(75600);
    expect($result->currency)->toBe('AUD');

    expect($result->lines)->toHaveCount(1);
    $line = $result->lines[0];
    expect($line->tyreVariantId)->toBe($variant->id);
    expect($line->quantity)->toBe(4);
    expect($line->unitPrice)->toBe(18900);
    expect($line->promotionalPrice)->toBeNull();
    expect($line->discountAmount)->toBe(0);
    expect($line->taxAmount)->toBe(1718);
    expect($line->lineTotal)->toBe(75600);
});

it('sums multiple lines into the aggregate totals', function () {
    $variantA = TyreVariant::factory()->create(['base_price' => 10000]);
    $variantB = TyreVariant::factory()->create(['base_price' => 5000]);

    $items = collect([
        new CartItemInput(tyreVariantId: $variantA->id, quantity: 2),
        new CartItemInput(tyreVariantId: $variantB->id, quantity: 1),
    ]);

    $result = $this->service->priceItems($items);

    // (10000*2) + (5000*1) = 25000
    expect($result->subtotal)->toBe(25000);
    expect($result->grandTotal)->toBe(25000);
    expect($result->lines)->toHaveCount(2);
});

it('derives pricing from a booking\'s line items for mode 2', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);
    $booking = Booking::factory()->create();
    BookingLineItem::factory()->create([
        'booking_id' => $booking->id,
        'tyre_variant_id' => $variant->id,
        'quantity' => 4,
    ]);

    $result = $this->service->priceBooking($booking->fresh());

    expect($result->subtotal)->toBe(75600);
    expect($result->taxTotal)->toBe(6873);
});
