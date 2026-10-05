<?php

namespace Database\Factories;

use App\Enums\PriceGuaranteeClaimStatus;
use App\Models\Customer;
use App\Models\PriceGuaranteeClaim;
use App\Models\TyreVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceGuaranteeClaim>
 */
class PriceGuaranteeClaimFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'order_id' => null,
            'competitor_url' => $this->faker->url(),
            'competitor_price' => $this->faker->numberBetween(5000, 50000),
            'tyre_variant_id' => TyreVariant::factory(),
            'status' => PriceGuaranteeClaimStatus::Pending,
            'approved_discount_amount' => null,
            'expires_at' => null,
            'redeemed_at' => null,
            'admin_note' => null,
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }

    public function approved(int $discountAmount): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PriceGuaranteeClaimStatus::Approved,
            'approved_discount_amount' => $discountAmount,
            'expires_at' => now()->addDays(30),
            'resolved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PriceGuaranteeClaimStatus::Rejected,
            'admin_note' => 'Not a matching competitor listing.',
            'resolved_at' => now(),
        ]);
    }
}
