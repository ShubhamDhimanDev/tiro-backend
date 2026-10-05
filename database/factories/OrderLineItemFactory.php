<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderLineItem;
use App\Models\TyreVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLineItem>
 */
class OrderLineItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = 18900;
        $quantity = 4;

        return [
            'order_id' => Order::factory(),
            'tyre_variant_id' => TyreVariant::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => 0,
            'tax_amount' => (int) round($unitPrice / 11),
            'line_total' => $unitPrice * $quantity,
        ];
    }
}
