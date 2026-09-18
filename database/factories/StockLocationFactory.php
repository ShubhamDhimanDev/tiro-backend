<?php

namespace Database\Factories;

use App\Models\StockLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLocation>
 */
class StockLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Depot',
            'address' => fake()->streetAddress(),
            'lat' => fake()->latitude(-38.0, -37.6),
            'lng' => fake()->longitude(144.7, 145.2),
        ];
    }
}
