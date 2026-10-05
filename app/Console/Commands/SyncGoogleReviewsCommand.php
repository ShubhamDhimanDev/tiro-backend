<?php

namespace App\Console\Commands;

use App\Enums\ReviewSource;
use App\Jobs\NotifyFrontendRevalidation;
use App\Models\Review;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Daily (`routes/console.php`, `dailyAt('03:00')`) and on-demand (via
 * `POST /admin/reviews/resync`, see
 * `App\Http\Controllers\Admin\Reviews\ReviewController::resync()`) sync of
 * Google Business Profile reviews into the local `reviews` table — see
 * docs/architecture/03-integrations.md item 5 and this phase's task brief.
 *
 * Deliberately the Business Profile APIs' `accounts.locations.reviews.list`
 * endpoint (the `mybusiness*` API family), NOT the Places API's `reviews`
 * field — Places' ToS explicitly forbids caching/storing review content
 * (fetch-live-only). This endpoint carries no such restriction, which is
 * the whole reason a synced local table is the correct design here.
 *
 * **Auth**: OAuth 2.0 user-delegated (3-legged) consent — materially
 * different from every other integration in this codebase (Stripe/Resend/
 * MessageMedia each use a service account or static API key). A real
 * person managing Tiro's GBP listing has to click through a one-time
 * consent grant (`business.manage` scope) before
 * `GOOGLE_REVIEWS_REFRESH_TOKEN` exists — external lead time, tracked
 * separately, not resolved by this command. Until it does, every run below
 * fails fast at the "not configured" guard; every test exercises this
 * entirely via `Http::fake()`, never a live call (no real credentials
 * exist yet). No new `OAuthToken` DB entity — this is a single, static,
 * business-owned credential stored as an env var
 * (`config('services.google_reviews.*')`), not a per-user token store, so
 * the refresh-token grant is re-exchanged for a short-lived access token
 * on every run rather than cached anywhere.
 *
 * **Upsert, not append**: {@see self::upsert()} writes only Google-sourced
 * fields — see {@see Review}'s docblock's moderation invariant.
 * `is_hidden` is never part of that write set, so an admin's moderation
 * decision on an existing row survives every subsequent sync untouched.
 *
 * **Revalidation** — dispatches exactly ONE `NotifyFrontendRevalidation`
 * job at the end of a successful run, tag `["reviews"]` — deliberately NOT
 * a per-model Observer (unlike `ContentPage`/`Faq`/`Brand`/`TyreModel`/
 * `TyreVariant`/`Promotion`, which each fire it via
 * `App\Observers\FrontendRevalidationObserver` on `saved`/`deleted`): a
 * sync run can upsert dozens of `Review` rows in a loop, and an Observer
 * per row would fire dozens of near-simultaneous webhook calls for what's
 * really one freshness event from the frontend's perspective. Contrast
 * `ReviewController::update()`, the one place a `Review` mutation DOES
 * dispatch it directly per-row — a genuine single-row admin edit, not a
 * batch.
 */
#[Signature('reviews:sync-google')]
#[Description('Sync Google Business Profile reviews into the local reviews table and trigger one frontend revalidation for the batch')]
class SyncGoogleReviewsCommand extends Command
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVIEWS_LIST_URL_TEMPLATE = 'https://mybusiness.googleapis.com/v4/accounts/%s/locations/%s/reviews';

    /**
     * Hard cap on `reviews.list` pages followed per run — a safety guard
     * against a malformed/looping `nextPageToken`, not a real expected
     * limit (a single mobile-tyre business's review count is never going
     * to approach 50 pages).
     */
    private const MAX_PAGES = 50;

    public function handle(): int
    {
        $clientId = (string) config('services.google_reviews.client_id');
        $clientSecret = (string) config('services.google_reviews.client_secret');
        $refreshToken = (string) config('services.google_reviews.refresh_token');
        $accountId = (string) config('services.google_reviews.account_id');
        $locationId = (string) config('services.google_reviews.location_id');

        if (blank($clientId) || blank($clientSecret) || blank($refreshToken) || blank($accountId) || blank($locationId)) {
            $this->warn('Skipped: GOOGLE_REVIEWS_* env vars are not fully configured yet (expected until the one-time OAuth consent grant happens — see this command\'s docblock).');

            return self::FAILURE;
        }

        $accessToken = $this->exchangeRefreshTokenForAccessToken($clientId, $clientSecret, $refreshToken);

        if ($accessToken === null) {
            return self::FAILURE;
        }

        $reviews = $this->fetchAllReviews($accessToken, $accountId, $locationId);

        if ($reviews === null) {
            return self::FAILURE;
        }

        $synced = 0;
        $skipped = 0;

        foreach ($reviews as $review) {
            if ($this->upsert($review)) {
                $synced++;
            } else {
                $skipped++;
            }
        }

        NotifyFrontendRevalidation::dispatch(['reviews']);

        $this->info("Synced {$synced} Google review(s)".($skipped > 0 ? ", skipped {$skipped} malformed row(s)" : '').'.');

        return self::SUCCESS;
    }

    private function exchangeRefreshTokenForAccessToken(string $clientId, string $clientSecret, string $refreshToken): ?string
    {
        $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $this->error("Google OAuth token exchange failed with status {$response->status()}.");
            Log::warning('reviews:sync-google: OAuth token exchange failed.', ['status' => $response->status()]);

            return null;
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            $this->error('Google OAuth token exchange returned no access_token.');
            Log::warning('reviews:sync-google: OAuth token exchange returned no access_token.');

            return null;
        }

        return $accessToken;
    }

    /**
     * @return list<array<string, mixed>>|null null on any page-fetch failure
     */
    private function fetchAllReviews(string $accessToken, string $accountId, string $locationId): ?array
    {
        $url = sprintf(self::REVIEWS_LIST_URL_TEMPLATE, $accountId, $locationId);

        $reviews = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = $pageToken !== null ? ['pageToken' => $pageToken] : [];

            $response = Http::withToken($accessToken)->timeout(15)->get($url, $query);

            if (! $response->successful()) {
                $this->error("Google reviews.list failed with status {$response->status()}.");
                Log::warning('reviews:sync-google: reviews.list failed.', ['status' => $response->status(), 'page' => $page]);

                return null;
            }

            /** @var list<array<string, mixed>> $pageReviews */
            $pageReviews = $response->json('reviews') ?? [];
            $reviews = [...$reviews, ...$pageReviews];

            $pageToken = $response->json('nextPageToken');

            if (! is_string($pageToken) || $pageToken === '') {
                break;
            }
        }

        return $reviews;
    }

    /**
     * Upsert one review — Google-sourced fields only, per the moderation
     * invariant in {@see Review}'s docblock. Returns false (and logs a
     * warning, skipping this one row) for a malformed entry rather than
     * aborting the whole batch — a single bad row from an external API
     * response shouldn't block every other review in the same run.
     *
     * @param  array<string, mixed>  $review
     */
    private function upsert(array $review): bool
    {
        $externalId = $review['reviewId'] ?? null;
        $starRatingRaw = $review['starRating'] ?? null;

        if (! is_string($externalId) || $externalId === '' || ! is_string($starRatingRaw)) {
            Log::warning('reviews:sync-google: skipping malformed review row.', ['review' => $review]);

            return false;
        }

        $rating = $this->parseStarRating($starRatingRaw);

        if ($rating === null) {
            Log::warning('reviews:sync-google: skipping review with unrecognized starRating.', ['reviewId' => $externalId, 'starRating' => $starRatingRaw]);

            return false;
        }

        $publishedAtRaw = $review['createTime'] ?? null;

        if (! is_string($publishedAtRaw) || $publishedAtRaw === '') {
            Log::warning('reviews:sync-google: skipping review with no createTime.', ['reviewId' => $externalId]);

            return false;
        }

        $replyBody = Arr::get($review, 'reviewReply.comment');
        $repliedAtRaw = Arr::get($review, 'reviewReply.updateTime');

        Review::updateOrCreate(
            ['source' => ReviewSource::Google, 'external_id' => $externalId],
            [
                'rating' => $rating,
                'author_name' => Arr::get($review, 'reviewer.displayName', 'Google User'),
                'author_photo_url' => Arr::get($review, 'reviewer.profilePhotoUrl'),
                'body' => $review['comment'] ?? null,
                // Not part of Google's documented `accounts.locations.reviews`
                // response schema as of this writing — read defensively in
                // case a future/regional payload variant includes it.
                // Nullable in the schema for exactly this reason; flagged
                // for project-architect to confirm the real attribution
                // link source once live credentials exist.
                'review_url' => $review['reviewUrl'] ?? null,
                'reply_body' => is_string($replyBody) ? $replyBody : null,
                'replied_at' => is_string($repliedAtRaw) ? Carbon::parse($repliedAtRaw) : null,
                'published_at' => Carbon::parse($publishedAtRaw),
                'cached_at' => now(),
            ],
        );

        return true;
    }

    /**
     * Google's `starRating` enum string ("ONE".."FIVE",
     * "STAR_RATING_UNSPECIFIED") -> this table's `rating`
     * unsignedTinyInteger (1-5). Exhaustive `match` — every recognized case
     * named explicitly, `default` returning `null` rather than throwing:
     * this project's usual "unhandled mapping value must throw, never
     * silently degrade" convention (see `App\Enums\NotificationChannel::
     * fromChannelName()`) is about catching a bug in code we control before
     * it ships; a malformed value from an external API mid-batch is neither
     * — throwing here would abort every other review in the same sync run
     * over one bad row, so this degrades to "skip this one review" (see
     * `upsert()`'s caller) with a logged warning instead.
     */
    private function parseStarRating(string $value): ?int
    {
        return match ($value) {
            'ONE' => 1,
            'TWO' => 2,
            'THREE' => 3,
            'FOUR' => 4,
            'FIVE' => 5,
            default => null,
        };
    }
}
