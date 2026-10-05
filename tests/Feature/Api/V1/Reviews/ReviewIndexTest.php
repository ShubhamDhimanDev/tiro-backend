<?php

use App\Models\Review;

/**
 * `GET /api/v1/reviews` — see this phase's task brief's "Public API"
 * section. Public, no auth.
 */
it('excludes hidden reviews and never exposes is_hidden', function () {
    $visible = Review::factory()->create();
    Review::factory()->hidden()->create();

    $response = $this->getJson('/api/v1/reviews');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.id', $visible->id);
    $response->assertJsonMissingPath('data.0.is_hidden');
});

it('orders reviews by published_at descending', function () {
    $older = Review::factory()->create(['published_at' => now()->subDays(5)]);
    $newer = Review::factory()->create(['published_at' => now()->subDay()]);

    $response = $this->getJson('/api/v1/reviews');

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $newer->id);
    $response->assertJsonPath('data.1.id', $older->id);
});

it('returns the documented item shape', function () {
    $review = Review::factory()->withReply()->create([
        'author_name' => 'J. Smith',
        'rating' => 5,
        'body' => 'Great service, quick and professional.',
        'review_url' => 'https://example.com/reviews/1',
    ]);

    $response = $this->getJson('/api/v1/reviews');

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $review->id);
    $response->assertJsonPath('data.0.source', 'google');
    $response->assertJsonPath('data.0.author_name', 'J. Smith');
    $response->assertJsonPath('data.0.rating', 5);
    $response->assertJsonPath('data.0.body', 'Great service, quick and professional.');
    $response->assertJsonPath('data.0.review_url', 'https://example.com/reviews/1');
    expect($response->json('data.0.reply_body'))->not->toBeNull();
    expect($response->json('data.0.published_at'))->not->toBeNull();
});

it('defaults to 10 per page', function () {
    Review::factory()->count(15)->create();

    $response = $this->getJson('/api/v1/reviews');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(10);
    $response->assertJsonPath('meta.per_page', 10);
});

it('rejects a per_page above the documented max of 50', function () {
    $response = $this->getJson('/api/v1/reviews?per_page=100');

    $response->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('computes the summary over the full non-hidden set, not just the current page', function () {
    Review::factory()->create(['rating' => 5]);
    Review::factory()->create(['rating' => 3]);
    Review::factory()->hidden()->create(['rating' => 1]); // excluded from both data and summary

    $response = $this->getJson('/api/v1/reviews?per_page=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('meta.total', 2);
    $response->assertJsonPath('meta.summary.total_count', 2);
    // Cast rather than `assertJsonPath` here: a whole-number average
    // (4.0) round-trips through `json_encode`/`json_decode` as a bare
    // int `4`, not the float literal `4.0` a strict `===` comparison
    // would need.
    expect((float) $response->json('meta.summary.average_rating'))->toBe(4.0);
});

it('returns a zero-value summary when there are no visible reviews', function () {
    Review::factory()->hidden()->create();

    $response = $this->getJson('/api/v1/reviews');

    $response->assertOk();
    $response->assertJsonPath('meta.summary.total_count', 0);
    expect((float) $response->json('meta.summary.average_rating'))->toBe(0.0);
});
