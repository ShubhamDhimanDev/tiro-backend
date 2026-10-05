<?php

namespace Database\Factories;

use App\Enums\ReviewSource;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => ReviewSource::Google,
            'external_id' => (string) $this->faker->unique()->uuid(),
            'rating' => $this->faker->numberBetween(1, 5),
            'author_name' => $this->faker->name(),
            'author_photo_url' => $this->faker->boolean(60) ? $this->faker->imageUrl() : null,
            'body' => $this->faker->boolean(80) ? $this->faker->paragraph() : null,
            'review_url' => 'https://search.google.com/local/reviews?placeid=fake&review='.$this->faker->uuid(),
            'reply_body' => null,
            'replied_at' => null,
            'location_id' => null,
            'is_hidden' => false,
            'published_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'cached_at' => now(),
        ];
    }

    /**
     * A review an admin has moderated out of the public listing.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => ['is_hidden' => true]);
    }

    /**
     * A review the business has already replied to.
     */
    public function withReply(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reply_body' => $this->faker->sentence(),
            'replied_at' => now(),
        ]);
    }
}
