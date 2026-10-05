<?php

namespace App\Http\Controllers\Api\V1\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reviews\ReviewIndexRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public read-only `Review` endpoint — see the Phase 8 task brief's
 * "Public API" section. Every row returned passes
 * {@see Review::scopeVisible()} (`is_hidden = false`); no caller bypasses
 * it here — staff moderation is a separate Inertia admin screen
 * (`App\Http\Controllers\Admin\Reviews\ReviewController`), not this API.
 */
class ReviewController extends Controller
{
    private const DEFAULT_PER_PAGE = 10;

    private const MAX_PER_PAGE = 50;

    public function index(ReviewIndexRequest $request): AnonymousResourceCollection
    {
        $perPage = min($request->integer('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        $paginator = Review::query()
            ->visible()
            ->orderByDesc('published_at')
            ->paginate($perPage)
            ->withQueryString();

        // `summary` is computed over the FULL unfiltered (non-hidden) set,
        // not just the current page — see the Phase 8 task brief's example
        // response shape. `total_count` reuses the paginator's own
        // already-computed total (identical value, same `visible()` base
        // query) rather than a second `count(*)` round trip; only
        // `average_rating` needs its own aggregate query.
        $averageRating = Review::query()->visible()->avg('rating');

        return ReviewResource::collection($paginator)->additional([
            'meta' => [
                'summary' => [
                    'average_rating' => $averageRating !== null ? round((float) $averageRating, 1) : 0.0,
                    'total_count' => $paginator->total(),
                    'distribution' => $this->distribution(),
                ],
            ],
        ]);
    }

    /**
     * Visible review counts per star rating; all five keys always present.
     *
     * @return array<string, int>
     */
    private function distribution(): array
    {
        $counts = Review::query()->visible()->selectRaw('rating, COUNT(*) as aggregate')->groupBy('rating')->pluck('aggregate', 'rating');

        return collect([5, 4, 3, 2, 1])->mapWithKeys(fn (int $rating): array => [(string) $rating => (int) ($counts[$rating] ?? 0)])->all();
    }
}
