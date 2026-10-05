/** Mirrors `App\Enums\ReviewSource` — Google only today, schema-ready for more. */
export type ReviewSource = 'google';

/**
 * Mirrors `App\Models\Review` as serialized by
 * `App\Http\Controllers\Admin\Reviews\ReviewController::index()` — the raw
 * model, not a Resource. `body` and `review_url` are both genuinely
 * nullable for real Google data (star-only ratings with no comment; Google's
 * API schema may omit a review URL entirely) — render both defensively.
 */
export type Review = {
    id: number;
    source: ReviewSource;
    external_id: string;
    rating: number;
    author_name: string;
    author_photo_url: string | null;
    body: string | null;
    review_url: string | null;
    reply_body: string | null;
    replied_at: string | null;
    location_id: number | null;
    is_hidden: boolean;
    published_at: string;
    cached_at: string;
};
