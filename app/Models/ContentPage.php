<?php

namespace App\Models;

use App\Contracts\RevalidatesFrontend;
use App\Enums\ContentPageType;
use App\Enums\PageStatus;
use Database\Factories\ContentPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One type-discriminated admin CMS entity — blog posts, guides, location
 * pages, promo-landing copy, and plain static pages all share this one
 * table/shape rather than five separate tables (see the Phase 6 task
 * brief). `service_zone_id`/`promotion_id` are both optional linkage only —
 * a location page is primarily static authored content and a promo landing
 * page might reference one specific {@see Promotion} or be general copy
 * with none; neither FK is ever a requirement.
 *
 * Visibility rule, enforced by every public read path (see
 * {@see scopePublished()}): `status = published AND (published_at IS NULL
 * OR published_at <= now())`. A `draft` row is never publicly visible
 * regardless of `published_at`. A `published` row with a future
 * `published_at` is scheduled, not yet live. `archived` is a soft-retire
 * (unpublish without deleting, preserves AuditLog history / allows
 * republish), distinct from a hard delete.
 *
 * @property int $id
 * @property ContentPageType $type
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $featured_image_path
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $og_image_path
 * @property string|null $category
 * @property PageStatus $status
 * @property Carbon|null $published_at
 * @property int|null $service_zone_id
 * @property int|null $promotion_id
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'type', 'title', 'slug', 'excerpt', 'body', 'featured_image_path', 'meta_title',
    'meta_description', 'og_image_path', 'category', 'status', 'published_at',
    'service_zone_id', 'promotion_id', 'sort_order',
])]
class ContentPage extends Model implements RevalidatesFrontend
{
    /** @use HasFactory<ContentPageFactory> */
    use HasFactory;

    /**
     * Get the service zone this page optionally references.
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    /**
     * Get the promotion this page optionally references.
     *
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * Get this page's own scoped FAQ block (global FAQs have
     * `content_page_id = null` and never appear here).
     *
     * @return HasMany<Faq, $this>
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }

    /**
     * Scope to rows currently visible on the public read API — see the
     * class docblock's visibility rule. Every `GET /api/v1/content/*`
     * endpoint applies this scope; no caller (including an authenticated
     * admin) ever bypasses it through this endpoint — an admin previews a
     * draft through the Inertia admin panel instead.
     *
     * @param  Builder<ContentPage>  $query
     * @return Builder<ContentPage>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', PageStatus::Published)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /**
     * ISR tags: the type's listing tag plus this page's own tag. Fires
     * unconditionally on create/update/delete (unlike `TyreModel`/
     * `TyreVariant`, this entity has no price/stock-only fields to filter
     * out — every column here is content).
     *
     * No `default => throw` arm — see `App\Models\Brand::revalidationTags()`'s
     * docblock for why (this single-arm match is provably exhaustive,
     * unlike the genuinely-external-input case this project's usual
     * exhaustive-enum convention targets).
     *
     * @param  'created'|'updated'|'deleted'  $event
     * @return list<string>
     */
    public function revalidationTags(string $event): array
    {
        return match ($event) {
            'created', 'updated', 'deleted' => [
                "content:{$this->type->value}",
                "content:{$this->type->value}:{$this->slug}",
            ],
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContentPageType::class,
            'status' => PageStatus::class,
            'published_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }
}
