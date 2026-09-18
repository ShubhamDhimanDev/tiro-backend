<?php

namespace Database\Factories;

use App\Models\RegoLookupCache;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Schema-only support factory — see {@see RegoLookupCache}'s docblock. No
 * business logic exists to exercise beyond basic persistence yet.
 *
 * @extends Factory<RegoLookupCache>
 */
class RegoLookupCacheFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rego' => fake()->unique()->bothify('???###'),
            'state' => fake()->randomElement(['VIC', 'NSW', 'QLD', 'WA', 'SA', 'TAS', 'ACT', 'NT']),
            'raw_response' => [],
            'resolved_vehicle_id' => null,
            'looked_up_at' => now(),
            'status' => null,
        ];
    }
}
