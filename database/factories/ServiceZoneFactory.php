<?php

namespace Database\Factories;

use App\Enums\ServiceZoneType;
use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceZone>
 */
class ServiceZoneFactory extends Factory
{
    /**
     * The default `operating_hours` shape — locked in
     * docs/architecture/01-data-model.md.
     *
     * @return array<string, array{open: string, close: string}|null>
     */
    public static function defaultOperatingHours(): array
    {
        return [
            'mon' => ['open' => '08:00', 'close' => '18:00'],
            'tue' => ['open' => '08:00', 'close' => '18:00'],
            'wed' => ['open' => '08:00', 'close' => '18:00'],
            'thu' => ['open' => '08:00', 'close' => '18:00'],
            'fri' => ['open' => '08:00', 'close' => '18:00'],
            'sat' => ['open' => '09:00', 'close' => '15:00'],
            'sun' => null,
        ];
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city().' Service Zone',
            'state_id' => State::factory(),
            'type' => ServiceZoneType::Radius,
            'origin_lat' => fake()->latitude(-38, -34),
            'origin_lng' => fake()->longitude(144, 151),
            'radius_km' => 20,
            'operating_hours' => static::defaultOperatingHours(),
            'priority' => 0,
            'status' => Status::Active,
        ];
    }

    /**
     * A radius-type zone: `origin_lat`/`origin_lng`/`radius_km` required.
     */
    public function radius(?float $lat = null, ?float $lng = null, float $radiusKm = 20): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ServiceZoneType::Radius,
            'origin_lat' => $lat ?? $attributes['origin_lat'],
            'origin_lng' => $lng ?? $attributes['origin_lng'],
            'radius_km' => $radiusKm,
        ]);
    }

    /**
     * A suburb-list-type zone: `origin_lat`/`origin_lng`/`radius_km` are
     * optional (kept as a display centroid only, not used for resolution).
     */
    public function suburbList(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ServiceZoneType::SuburbList,
            'radius_km' => null,
        ]);
    }
}
