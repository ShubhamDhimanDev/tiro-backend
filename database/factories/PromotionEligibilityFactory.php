<?php

namespace Database\Factories;

use App\Enums\PromotionEligibilityScope;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\TyreVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionEligibility>
 */
class PromotionEligibilityFactory extends Factory
{
    /**
     * Define the model's default state — defaults to scoping against a
     * fresh `TyreVariant` (the narrowest, always-resolvable scope).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'scope' => PromotionEligibilityScope::TyreVariant,
            'scope_id' => (string) TyreVariant::factory()->create()->id,
            'service_zone_id' => null,
        ];
    }

    public function forVariant(TyreVariant $variant): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => PromotionEligibilityScope::TyreVariant,
            'scope_id' => (string) $variant->id,
        ]);
    }

    public function forBrand(int $brandId): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => PromotionEligibilityScope::Brand,
            'scope_id' => (string) $brandId,
        ]);
    }

    public function forCategory(string $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => PromotionEligibilityScope::Category,
            'scope_id' => $category,
        ]);
    }

    public function forZone(int $serviceZoneId): static
    {
        return $this->state(fn (array $attributes): array => ['service_zone_id' => $serviceZoneId]);
    }
}
