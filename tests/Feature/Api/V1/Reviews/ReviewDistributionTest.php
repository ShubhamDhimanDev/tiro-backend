<?php

use App\Models\Review;

/**
 * `GET /api/v1/reviews` `meta.summary.distribution` (see
 * docs/redesign/api-contract-phase7.md section 8).
 */
it('returns per-star counts over visible reviews with all five keys', function () {
    Review::factory()->count(2)->create(['rating' => 5, 'is_hidden' => false]);
    Review::factory()->create(['rating' => 3, 'is_hidden' => false]);
    Review::factory()->create(['rating' => 1, 'is_hidden' => true]);

    $this->getJson('/api/v1/reviews')
        ->assertOk()
        ->assertJsonPath('meta.summary.distribution', ['5' => 2, '4' => 0, '3' => 1, '2' => 0, '1' => 0])
        ->assertJsonPath('meta.summary.total_count', 3);
});

it('returns zeros when there are no reviews', function () {
    $this->getJson('/api/v1/reviews')
        ->assertOk()
        ->assertJsonPath('meta.summary.distribution', ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0]);
});
