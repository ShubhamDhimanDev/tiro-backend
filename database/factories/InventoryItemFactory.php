<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\StockLocation;
use App\Models\TyreVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tyre_variant_id' => TyreVariant::factory(),
            'stock_location_id' => StockLocation::factory(),
            'qty_on_hand' => fake()->numberBetween(0, 50),
            'qty_reserved' => 0,
            'reorder_point' => fake()->numberBetween(2, 10),
        ];
    }
}
