<?php

namespace Database\Factories;

use App\Enums\ContentPageType;
use App\Enums\PageStatus;
use App\Models\ContentPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentPage>
 */
class ContentPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(4);

        // Faker's `paragraphs($count, $asText)` is declared `array|string`
        // regardless of the `$asText` argument's value — phpstan can't
        // narrow it to `string` just because `true` was passed literally —
        // same `is_string()` guard already used by
        // `TyreModelFactory::definition()`'s `words(3, true)` call for the
        // identical reason.
        $bodyParagraphs = $this->faker->paragraphs(3, true);
        $bodyText = is_string($bodyParagraphs) ? $bodyParagraphs : implode(' ', $bodyParagraphs);

        return [
            'type' => ContentPageType::Page,
            'title' => $title,
            'slug' => Str::slug($title),
            'excerpt' => $this->faker->boolean(70) ? $this->faker->sentence() : null,
            'body' => "<p>{$bodyText}</p>",
            'featured_image_path' => null,
            'meta_title' => null,
            'meta_description' => null,
            'og_image_path' => null,
            'category' => null,
            'status' => PageStatus::Draft,
            'published_at' => null,
            'service_zone_id' => null,
            'promotion_id' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * A page currently visible on the public read API — see
     * `ContentPage::scopePublished()`'s visibility rule.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PageStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * A `published` row scheduled in the future — visible in the admin,
     * but not yet publicly visible.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PageStatus::Published,
            'published_at' => now()->addWeek(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PageStatus::Archived,
            'published_at' => now()->subMonth(),
        ]);
    }

    public function ofType(ContentPageType $type): static
    {
        return $this->state(fn (array $attributes): array => ['type' => $type]);
    }
}
