<?php

namespace Database\Factories;

use App\Enums\ServiceZoneType;
use App\Models\ServiceZone;
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

    /**
     * A suburb the given zone serves: in the zone's state, at a radius zone's
     * origin (distance 0, so no other zone can be nearer), and added to a
     * suburb-list zone's suburbs.
     */
    public function servicedBy(ServiceZone $zone): static
    {
        return $this
            ->state(fn (array $attributes): array => [
                'state_id' => $zone->state_id,
                'lat' => $zone->origin_lat ?? $attributes['lat'],
                'lng' => $zone->origin_lng ?? $attributes['lng'],
            ])
            ->afterCreating(function (Suburb $suburb) use ($zone): void {
                if ($zone->type === ServiceZoneType::SuburbList) {
                    $zone->suburbs()->attach($suburb);
                }
            });
    }
}
