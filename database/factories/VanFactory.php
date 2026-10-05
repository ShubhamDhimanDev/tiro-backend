<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\StockLocation;
use App\Models\Van;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Van>
 */
class VanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rego' => strtoupper(fake()->unique()->bothify('??##??')),
            'name' => 'Van '.fake()->unique()->numberBetween(1, 999),
            'home_stock_location_id' => StockLocation::factory(),
            'has_alignment_equipment' => false,
            'max_jobs_per_day' => 8,
            'status' => Status::Active,
        ];
    }

    /**
     * A van equipped for alignment jobs.
     */
    public function withAlignmentEquipment(): static
    {
        return $this->state(fn (array $attributes) => ['has_alignment_equipment' => true]);
    }
}
