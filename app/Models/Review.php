<?php

namespace App\Models;

use App\Enums\ReviewSource;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A synced review from an external platform — currently Google only (see
 * {@see ReviewSource}), written exclusively by
 * `App\Console\Commands\SyncGoogleReviewsCommand`'s daily/manual sync. No
 * admin create/delete: review content only ever originates from the sync
 * job.
 *
 * **Moderation invariant, critical:** `is_hidden` is admin-set (via
 * `PATCH /admin/reviews/{review}`) and the sync upsert must NEVER overwrite
 * it. The upsert only ever writes the Google-sourced fields listed below;
 * `is_hidden` defaults to `false` only on first insert
 * (`updateOrCreate()`'s create-defaults, never touched on update) — see the
 * sync command's own docblock for the exact upsert call.
 *
 * `location_id` stays unset (`null`) for every row under the current
 * default — working assumption is one GBP listing for the whole business
 * (Tiro is a mobile service, not a chain of storefronts). Schema-ready for
 * a future per-listing split, not built on assuming one.
 *
 * @property int $id
 * @property ReviewSource $source
 * @property string $external_id
 * @property int $rating
 * @property string $author_name
 * @property string|null $author_photo_url
 * @property string|null $body
 * @property string|null $review_url
 * @property string|null $reply_body
 * @property Carbon|null $replied_at
 * @property int|null $location_id
 * @property bool $is_hidden
 * @property Carbon $published_at
 * @property Carbon $cached_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'source', 'external_id', 'rating', 'author_name', 'author_photo_url', 'body',
    'review_url', 'reply_body', 'replied_at', 'location_id', 'is_hidden',
    'published_at', 'cached_at',
])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /**
     * The optional single GBP listing this review belongs to — see the
     * class docblock, always null under the current default.
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class, 'location_id');
    }

    /**
     * Scope to rows visible on the public read API — `is_hidden = false`,
     * the exact filter `GET /api/v1/reviews` applies. A hidden review's
     * non-existence to the public API is indistinguishable from it never
     * having synced, matching every other public content endpoint's
     * posture in this project. The admin listing deliberately does NOT
     * apply this scope — staff need to see hidden rows too.
     *
     * @param  Builder<Review>  $query
     * @return Builder<Review>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ReviewSource::class,
            'rating' => 'integer',
            'replied_at' => 'datetime',
            'location_id' => 'integer',
            'is_hidden' => 'boolean',
            'published_at' => 'datetime',
            'cached_at' => 'datetime',
        ];
    }
}
