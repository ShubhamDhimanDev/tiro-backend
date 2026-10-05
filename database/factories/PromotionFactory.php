<?php

namespace Database\Factories;

use App\Enums\PromotionType;
use App\Enums\Status;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'type' => PromotionType::Percentage,
            'value' => 10,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'usage_limit' => null,
            'usage_count' => 0,
            'stock_limit' => null,
            'stackable' => false,
            'status' => Status::Active,
        ];
    }

    /**
     * A public offer visible on `GET /api/v1/offers` (needs a slug).
     */
    public function publicOffer(?string $slug = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_public' => true,
            'slug' => $slug ?? Str::slug($attributes['name']).'-'.fake()->unique()->numerify('####'),
            'title' => ucfirst($attributes['name']),
            'summary' => 'Summary for '.$attributes['name'],
        ]);
    }

    /**
     * A typed-code promotion: never auto-applied.
     */
    public function withCode(string $code): static
    {
        return $this->state(fn (array $attributes): array => ['code' => $code]);
    }

    public function fourForThree(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => PromotionType::FourForThree,
            'value' => 0,
        ]);
    }

    public function fixed(int $valueCents): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => PromotionType::Fixed,
            'value' => $valueCents,
        ]);
    }

    public function stackable(): static
    {
        return $this->state(fn (array $attributes): array => ['stackable' => true]);
    }

    public function withStockLimit(int $stockLimit): static
    {
        return $this->state(fn (array $attributes): array => ['stock_limit' => $stockLimit]);
    }

    public function withUsageLimit(int $usageLimit): static
    {
        return $this->state(fn (array $attributes): array => ['usage_limit' => $usageLimit]);
    }
}
