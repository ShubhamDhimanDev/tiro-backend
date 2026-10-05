<?php

namespace Database\Factories;

use App\Enums\CancellationFeeType;
use App\Enums\Status;
use App\Models\CancellationPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CancellationPolicy>
 */
class CancellationPolicyFactory extends Factory
{
    /**
     * Define the model's default state — mirrors the seeded permissive
     * global default row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_zone_id' => null,
            'notice_hours' => 0,
            'fee_type' => CancellationFeeType::Flat,
            'fee_amount' => 0,
            'fee_percent' => null,
            'status' => Status::Active,
        ];
    }
}
