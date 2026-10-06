<?php

namespace Database\Factories;

use App\Enums\MediaStatus;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'original_name' => fake()->word().'.jpg',
            'status' => MediaStatus::Pending,
        ];
    }

    public function ready(): static
    {
        return $this->state(function (): array {
            $name = Str::uuid()->toString();

            return [
                'status' => MediaStatus::Ready,
                'path' => "media/2026/10/{$name}.webp",
                'thumb_path' => "media/2026/10/{$name}-thumb.webp",
                'mime' => 'image/webp',
                'size' => 12345,
                'width' => 800,
                'height' => 600,
            ];
        });
    }

    public function failed(): static
    {
        return $this->state(['status' => MediaStatus::Failed, 'error' => 'Could not decode image.']);
    }
}
