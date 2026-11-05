<?php

namespace Database\Factories;

use App\Models\SizePriceBand;
use App\Models\SizePriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SizePriceTier>
 */
class SizePriceTierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'size_price_band_id' => SizePriceBand::factory(),
            'quantity' => 1,
            'total_price' => 16000,
        ];
    }
}
