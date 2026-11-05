<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\SizePriceBand;
use App\Models\SizePriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SizePriceBand>
 */
class SizePriceBandFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Rim '.fake()->unique()->numberBetween(10, 30),
            'rim_min' => 16,
            'rim_max' => 17,
            'status' => Status::Active,
        ];
    }

    public function rims(int $min, int $max): static
    {
        return $this->state(fn (): array => ['rim_min' => $min, 'rim_max' => $max, 'name' => "{$min}-{$max} inch"]);
    }

    /**
     * Totals (cents) for 1, 2, 3… tyres, in order.
     *
     * @param  list<int>  $totals
     */
    public function withTiers(array $totals): static
    {
        return $this->afterCreating(function (SizePriceBand $band) use ($totals): void {
            foreach (array_values($totals) as $index => $total) {
                SizePriceTier::query()->create([
                    'size_price_band_id' => $band->id,
                    'quantity' => $index + 1,
                    'total_price' => $total,
                ]);
            }
        });
    }
}
