<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\Van;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicianShift>
 */
class TechnicianShiftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'technician_id' => Technician::factory(),
            'van_id' => Van::factory(),
            'service_zone_id' => ServiceZone::factory(),
            'date' => now()->addDay()->toDateString(),
            'shift_start' => '09:00:00',
            'shift_end' => '17:00:00',
            'status' => Status::Active,
        ];
    }
}
