<?php

namespace Database\Factories;

use App\Enums\DurationRuleAppliesTo;
use App\Enums\Status;
use App\Models\DurationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DurationRule>
 */
class DurationRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'applies_to' => DurationRuleAppliesTo::Base,
            'key' => 'setup_overhead',
            'minutes' => fake()->numberBetween(10, 30),
            'status' => Status::Active,
        ];
    }
}
