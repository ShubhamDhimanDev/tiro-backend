<?php

namespace Database\Factories;

use App\Models\ServiceZone;
use App\Models\ServiceZoneSuburb;
use App\Models\Suburb;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceZoneSuburb>
 */
class ServiceZoneSuburbFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_zone_id' => ServiceZone::factory(),
            'suburb_id' => Suburb::factory(),
        ];
    }
}
