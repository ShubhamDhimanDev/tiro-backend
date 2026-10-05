<?php

namespace Database\Factories;

use App\Enums\PageStatus;
use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question' => $this->faker->sentence().'?',
            'answer' => $this->faker->paragraph(),
            'category' => null,
            'content_page_id' => null,
            'sort_order' => 0,
            'status' => PageStatus::Draft,
        ];
    }

    /**
     * An FAQ currently visible on the public read API.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => PageStatus::Published]);
    }
}
