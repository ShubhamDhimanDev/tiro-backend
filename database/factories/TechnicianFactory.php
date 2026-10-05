<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Enums\TechnicianEmploymentType;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Technician>
 */
class TechnicianFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => fake()->name(),
            'employment_type' => TechnicianEmploymentType::Employee,
            'certifications' => null,
            'status' => Status::Active,
        ];
    }
}
