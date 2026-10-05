<?php

namespace Database\Factories;

use App\Enums\AddressType;
use App\Models\Address;
use App\Models\Suburb;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => null,
            'label' => null,
            'suburb_id' => Suburb::factory(),
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'postcode' => fake()->numerify('####'),
            'lat' => fake()->latitude(-38.0, -37.6),
            'lng' => fake()->longitude(144.7, 145.2),
            'access_instructions' => null,
            'type' => AddressType::Fitting,
            'is_default' => false,
        ];
    }
}
