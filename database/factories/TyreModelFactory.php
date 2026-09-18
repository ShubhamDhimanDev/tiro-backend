<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Enums\TyreConstruction;
use App\Enums\TyreType;
use App\Models\Brand;
use App\Models\TyreModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TyreModel>
 */
class TyreModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $words = fake()->unique()->words(3, true);
        $name = is_string($words) ? $words : implode(' ', $words);

        return [
            'brand_id' => Brand::factory(),
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'category' => fake()->randomElement(TyreCategory::cases()),
            'tyre_type' => fake()->randomElement(TyreType::cases()),
            'construction' => TyreConstruction::Radial,
            'run_flat' => fake()->boolean(15),
            'description' => fake()->paragraph(),
            'warranty_text' => fake()->sentence(),
            'warranty_km' => fake()->randomElement([60000, 80000, 100000]),
            'service_inclusions' => ['Fitting', 'Computer balancing', 'New valves', 'Old tyre disposal'],
            'released_at' => fake()->dateTimeBetween('-3 years', 'now'),
            'images' => [],
            'status' => Status::Active,
        ];
    }
}
