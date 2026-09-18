<?php

namespace Database\Factories;

use App\Models\ServiceZone;
use App\Models\ServiceZoneStockLocation;
use App\Models\StockLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceZoneStockLocation>
 */
class ServiceZoneStockLocationFactory extends Factory
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
            'stock_location_id' => StockLocation::factory(),
        ];
    }
}
