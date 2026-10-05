<?php

use App\Enums\ReviewSource;
use App\Jobs\NotifyFrontendRevalidation;
use App\Models\Review;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/**
 * `php artisan reviews:sync-google` — see
 * `App\Console\Commands\SyncGoogleReviewsCommand`'s docblock. Every test
 * exercises this entirely via `Http::fake()`, never a real call — no real
 * Google credentials exist yet (the one-time OAuth consent grant hasn't
 * happened).
 */
beforeEach(function () {
    config([
        'services.google_reviews.client_id' => 'test-client-id',
        'services.google_reviews.client_secret' => 'test-client-secret',
        'services.google_reviews.refresh_token' => 'test-refresh-token',
        'services.google_reviews.account_id' => '12345',
        'services.google_reviews.location_id' => '67890',
    ]);
});

function fakeGoogleTokenResponse(): array
{
    return ['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3599], 200)];
}

it('skips and fails fast when GOOGLE_REVIEWS_* env vars are not fully configured', function () {
    config(['services.google_reviews.refresh_token' => null]);
    Bus::fake();

    $this->artisan('reviews:sync-google')->assertExitCode(1);

    Bus::assertNotDispatched(NotifyFrontendRevalidation::class);
    expect(Review::query()->count())->toBe(0);
});

it('upserts reviews from a successful sync and dispatches exactly one revalidation job', function () {
    Bus::fake();
    Http::fake([
        ...fakeGoogleTokenResponse(),
        'mybusiness.googleapis.com/*' => Http::response([
            'reviews' => [
                [
                    'reviewId' => 'review-1',
                    'reviewer' => ['displayName' => 'Jane Smith', 'profilePhotoUrl' => 'https://example.com/jane.jpg'],
                    'starRating' => 'FIVE',
                    'comment' => 'Great service.',
                    'createTime' => '2026-08-14T10:00:00Z',
                    'reviewUrl' => 'https://example.com/reviews/review-1',
                ],
                [
                    'reviewId' => 'review-2',
                    'reviewer' => ['displayName' => 'Sam Lee'],
                    'starRating' => 'FOUR',
                    'comment' => null,
                    'createTime' => '2026-07-01T08:30:00Z',
                    'reviewReply' => ['comment' => 'Thanks Sam!', 'updateTime' => '2026-07-02T09:00:00Z'],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('reviews:sync-google')->assertExitCode(0);

    expect(Review::query()->count())->toBe(2);

    $review1 = Review::query()->where('external_id', 'review-1')->firstOrFail();
    expect($review1->source)->toBe(ReviewSource::Google);
    expect($review1->rating)->toBe(5);
    expect($review1->author_name)->toBe('Jane Smith');
    expect($review1->author_photo_url)->toBe('https://example.com/jane.jpg');
    expect($review1->body)->toBe('Great service.');
    expect($review1->review_url)->toBe('https://example.com/reviews/review-1');
    expect($review1->reply_body)->toBeNull();
    expect($review1->is_hidden)->toBeFalse();
    expect($review1->published_at->timestamp)->toBe(strtotime('2026-08-14T10:00:00Z'));

    $review2 = Review::query()->where('external_id', 'review-2')->firstOrFail();
    expect($review2->rating)->toBe(4);
    expect($review2->body)->toBeNull();
    expect($review2->reply_body)->toBe('Thanks Sam!');
    expect($review2->replied_at)->not->toBeNull();

    Bus::assertDispatchedTimes(NotifyFrontendRevalidation::class, 1);
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ['reviews']);
});

it('never overwrites an admin-set is_hidden flag on re-sync', function () {
    Bus::fake();
    Review::factory()->hidden()->create(['source' => ReviewSource::Google, 'external_id' => 'review-1']);

    Http::fake([
        ...fakeGoogleTokenResponse(),
        'mybusiness.googleapis.com/*' => Http::response([
            'reviews' => [[
                'reviewId' => 'review-1',
                'reviewer' => ['displayName' => 'Jane Smith'],
                'starRating' => 'FIVE',
                'comment' => 'Updated comment from Google.',
                'createTime' => '2026-08-14T10:00:00Z',
            ]],
        ], 200),
    ]);

    $this->artisan('reviews:sync-google')->assertExitCode(0);

    $review = Review::query()->where('external_id', 'review-1')->firstOrFail();
    expect($review->is_hidden)->toBeTrue();
    expect($review->body)->toBe('Updated comment from Google.');
});

it('fails without dispatching a revalidation job when the OAuth token exchange fails', function () {
    Bus::fake();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $this->artisan('reviews:sync-google')->assertExitCode(1);

    Bus::assertNotDispatched(NotifyFrontendRevalidation::class);
    expect(Review::query()->count())->toBe(0);
});

it('fails without dispatching a revalidation job when reviews.list fails', function () {
    Bus::fake();
    Http::fake([
        ...fakeGoogleTokenResponse(),
        'mybusiness.googleapis.com/*' => Http::response(['error' => 'permission denied'], 403),
    ]);

    $this->artisan('reviews:sync-google')->assertExitCode(1);

    Bus::assertNotDispatched(NotifyFrontendRevalidation::class);
});

it('skips a malformed review row without aborting the rest of the batch', function () {
    Bus::fake();
    Http::fake([
        ...fakeGoogleTokenResponse(),
        'mybusiness.googleapis.com/*' => Http::response([
            'reviews' => [
                ['reviewId' => 'review-bad', 'starRating' => 'NOT_A_REAL_RATING', 'createTime' => '2026-08-14T10:00:00Z', 'reviewer' => ['displayName' => 'Bad Row']],
                ['reviewId' => 'review-good', 'starRating' => 'THREE', 'createTime' => '2026-08-14T10:00:00Z', 'reviewer' => ['displayName' => 'Good Row']],
            ],
        ], 200),
    ]);

    $this->artisan('reviews:sync-google')->assertExitCode(0);

    expect(Review::query()->count())->toBe(1);
    expect(Review::query()->where('external_id', 'review-good')->exists())->toBeTrue();
    Bus::assertDispatched(NotifyFrontendRevalidation::class);
});

it('follows nextPageToken to fetch every page', function () {
    Bus::fake();
    Http::fake([
        ...fakeGoogleTokenResponse(),
        'mybusiness.googleapis.com/*pageToken=next-page*' => Http::response([
            'reviews' => [['reviewId' => 'review-page-2', 'starRating' => 'TWO', 'createTime' => '2026-01-01T00:00:00Z', 'reviewer' => ['displayName' => 'Page Two']]],
        ], 200),
        'mybusiness.googleapis.com/*' => Http::response([
            'reviews' => [['reviewId' => 'review-page-1', 'starRating' => 'ONE', 'createTime' => '2026-01-02T00:00:00Z', 'reviewer' => ['displayName' => 'Page One']]],
            'nextPageToken' => 'next-page',
        ], 200),
    ]);

    $this->artisan('reviews:sync-google')->assertExitCode(0);

    expect(Review::query()->count())->toBe(2);
    expect(Review::query()->where('external_id', 'review-page-2')->exists())->toBeTrue();
});
