<?php

namespace App\Http\Controllers\Admin\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reviews\ReviewUpdateRequest;
use App\Jobs\NotifyFrontendRevalidation;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin moderation for {@see Review} — gated `content.view`/`content.manage`
 * at the route level (`routes/admin.php`), per this phase's task brief.
 * Review content only ever originates from `reviews:sync-google`
 * ({@see App\Console\Commands\SyncGoogleReviewsCommand}); there is no
 * create/delete here, only the `is_hidden` moderation toggle and a manual
 * "run the sync now" lever.
 */
class ReviewController extends Controller
{
    /**
     * Display every review, hidden and visible alike — staff need to see
     * moderated-out rows too, unlike the public `GET /api/v1/reviews`
     * endpoint, which excludes them entirely (see
     * {@see Review::scopeVisible()}'s docblock).
     */
    public function index(): Response
    {
        $reviews = Review::query()
            ->orderByDesc('published_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('reviews/index', [
            'reviews' => $reviews,
        ]);
    }

    /**
     * Toggle a review's moderation state. A genuine single-row edit, so it
     * dispatches the `reviews` revalidation tag directly here (same
     * `NotifyFrontendRevalidation` job every Observer-driven trigger uses
     * elsewhere in this project) — {@see Review} deliberately has no
     * `App\Observers\FrontendRevalidationObserver` registration; see
     * `SyncGoogleReviewsCommand`'s docblock for why a per-model Observer is
     * the wrong shape for that batch sync path.
     */
    public function update(ReviewUpdateRequest $request, Review $review): RedirectResponse
    {
        $review->update(['is_hidden' => $request->boolean('is_hidden')]);

        NotifyFrontendRevalidation::dispatch(['reviews']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $review->is_hidden
                ? __('Review hidden from the public listing.')
                : __('Review restored to the public listing.'),
        ]);

        return back();
    }

    /**
     * Manual "run the sync now" lever for ops, rather than waiting for the
     * 3am schedule tick (`routes/console.php`). Runs `reviews:sync-google`
     * inline — the exact same command the schedule invokes, no duplicated
     * sync logic — so this request blocks on the OAuth exchange + Google
     * API call, bounded by that command's own HTTP timeouts (see its
     * docblock).
     */
    public function resync(): RedirectResponse
    {
        $exitCode = Artisan::call('reviews:sync-google');

        Inertia::flash('toast', [
            'type' => $exitCode === 0 ? 'success' : 'error',
            'message' => $exitCode === 0
                ? __('Google reviews synced.')
                : __('Google reviews sync failed — check the logs.'),
        ]);

        return back();
    }
}
