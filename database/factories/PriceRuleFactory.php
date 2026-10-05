<?php

namespace Database\Factories;

use App\Enums\CancellationFeeType;
use App\Enums\Status;
use App\Models\PriceRule;
use App\Models\ServiceZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceRule>
 */
class PriceRuleFactory extends Factory
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
            'fee_type' => CancellationFeeType::Flat,
            'fee_amount' => 0,
            'fee_percent' => null,
            'status' => Status::Active,
        ];
    }

    public function flat(int $amountCents): static
    {
        return $this->state(fn (array $attributes): array => [
            'fee_type' => CancellationFeeType::Flat,
            'fee_amount' => $amountCents,
            'fee_percent' => null,
        ]);
    }

    public function percent(int $percent): static
    {
        return $this->state(fn (array $attributes): array => [
            'fee_type' => CancellationFeeType::Percent,
            'fee_amount' => null,
            'fee_percent' => $percent,
        ]);
    }
}
