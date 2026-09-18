<?php

namespace Database\Factories;

use App\Models\State;
use App\Models\Suburb;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suburb>
 */
class SuburbFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city(),
            'state_id' => State::factory(),
            'postcode' => fake()->numerify('####'),
            // Real, correctly-located coordinates matter here — radius-zone
            // matching does real distance math against these in tests.
            // Default to a real Melbourne CBD-area point; override per-test.
            'lat' => fake()->latitude(-38.0, -37.6),
            'lng' => fake()->longitude(144.7, 145.2),
        ];
    }
}
