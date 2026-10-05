<?php

namespace Database\Factories;

use App\Enums\PromotionRedemptionStatus;
use App\Models\Booking;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionRedemption>
 */
class PromotionRedemptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'booking_id' => Booking::factory(),
            'order_id' => null,
            'customer_id' => null,
            'quantity' => 1,
            'discount_amount' => null,
            'status' => PromotionRedemptionStatus::Held,
            'hold_expires_at' => now()->addMinutes(15),
            'redeemed_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PromotionRedemptionStatus::Confirmed,
            'hold_expires_at' => null,
            'redeemed_at' => now(),
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PromotionRedemptionStatus::Released,
            'hold_expires_at' => null,
        ]);
    }
}
