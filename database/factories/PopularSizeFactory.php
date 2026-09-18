<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\PopularSize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PopularSize>
 */
class PopularSizeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'width' => fake()->randomElement([185, 195, 205, 215, 225, 235]),
            'profile' => fake()->randomElement([45, 50, 55, 60, 65]),
            'rim_diameter' => fake()->randomElement([16, 17, 18]),
            'sort_order' => fake()->numberBetween(0, 100),
            'status' => Status::Active,
        ];
    }
}
