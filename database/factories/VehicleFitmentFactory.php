<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Enums\VehicleFitmentConfidence;
use App\Enums\VehicleFitmentPosition;
use App\Enums\VehicleFitmentSource;
use App\Models\Vehicle;
use App\Models\VehicleFitment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleFitment>
 */
class VehicleFitmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'position' => VehicleFitmentPosition::All,
            'width' => fake()->randomElement([185, 195, 205, 215, 225]),
            'profile' => fake()->randomElement([45, 50, 55, 60, 65]),
            'rim_diameter' => fake()->randomElement([15, 16, 17, 18]),
            'load_index' => (string) fake()->numberBetween(88, 108),
            'speed_rating' => fake()->randomElement(['H', 'V', 'W', 'T']),
            'is_staggered' => false,
            'source' => VehicleFitmentSource::Manual,
            'confidence' => VehicleFitmentConfidence::Confirmed,
            'notes' => null,
            'status' => Status::Active,
        ];
    }

    /**
     * A front-position row of a staggered pair.
     */
    public function front(): static
    {
        return $this->state(fn () => ['position' => VehicleFitmentPosition::Front, 'is_staggered' => true]);
    }

    /**
     * A rear-position row of a staggered pair.
     */
    public function rear(): static
    {
        return $this->state(fn () => [
            'position' => VehicleFitmentPosition::Rear,
            'is_staggered' => true,
            'width' => 245,
            'profile' => 40,
            'rim_diameter' => 18,
        ]);
    }
}
