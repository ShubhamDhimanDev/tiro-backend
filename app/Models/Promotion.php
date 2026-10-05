<?php

namespace App\Models;

use App\Contracts\RevalidatesFrontend;
use App\Enums\PromotionType;
use App\Enums\Status;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An auto-applied marketing/pricing campaign — see
 * docs/architecture/05-promotions-pricing.md for the evaluation algorithm
 * and docs/architecture/01-data-model.md's "Promotions & pricing" section
 * for the schema. Originally auto-applied only (decision #18); Phase 6a adds
 * an optional `code`: a promotion with a code is never auto-applied and only
 * applies when the customer supplies that code (see
 * `PromotionEvaluationService`). `is_public` gates the public offers listing.
 *
 * `usage_limit`/`usage_count` and `stock_limit` are two distinct caps, not
 * the same concept under two names — see
 * `App\Services\Promotions\PromotionEvaluationService`'s docblock.
 *
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property string|null $slug
 * @property string|null $title
 * @property string|null $summary
 * @property string|null $badge_text
 * @property string|null $discount_description
 * @property string|null $terms
 * @property string|null $image_path
 * @property bool $is_public
 * @property PromotionType $type
 * @property int $value
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int|null $usage_limit
 * @property int $usage_count
 * @property int|null $stock_limit
 * @property bool $stackable
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'code', 'slug', 'title', 'summary', 'badge_text', 'discount_description', 'terms', 'image_path', 'is_public', 'type', 'value', 'starts_at', 'ends_at', 'usage_limit', 'usage_count', 'stock_limit', 'stackable', 'status'])]
class Promotion extends Model implements RevalidatesFrontend
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    /**
     * Promo codes are stored and compared upper-case, whitespace-trimmed, so
     * "spring10 " and "SPRING10" are the same code.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => ($value === null || trim($value) === '') ? null : strtoupper(trim($value)),
        );
    }

    /**
     * Get this promotion's eligibility rules (brand/tyre_model/tyre_variant/
     * category, optionally zone-scoped).
     *
     * @return HasMany<PromotionEligibility, $this>
     */
    public function eligibilities(): HasMany
    {
        return $this->hasMany(PromotionEligibility::class);
    }

    /**
     * Get every hold/redemption record against this promotion.
     *
     * @return HasMany<PromotionRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /**
     * Snapshot of linked `ContentPage` tags, captured in `deleting` (fires
     * *before* the row is actually removed) for `revalidationTags('deleted')`
     * to reuse. Necessary specifically for the delete path:
     * `content_pages.promotion_id` is `nullOnDelete()` (see
     * `2026_09_23_121417_create_content_pages_table.php`), and MySQL applies
     * that `ON DELETE SET NULL` referential action as part of the same
     * `DELETE` statement that removes this row — confirmed empirically, not
     * assumed, by probing whether a `ContentPage` linked to a `Promotion`
     * still reported a non-null `promotion_id` from inside that promotion's
     * own `deleted` event handler (it did not: MySQL had already nulled it
     * out by then). A live query in `revalidationTags('deleted')` would
     * therefore always see the FK already cleared and silently return zero
     * `ContentPage` tags — exactly the gap this whole fix exists to close,
     * reintroduced for the delete path alone if not captured earlier.
     *
     * @var list<string>|null
     */
    private ?array $contentPageTagsBeforeDelete = null;

    protected static function booted(): void
    {
        static::deleting(function (self $promotion): void {
            $promotion->contentPageTagsBeforeDelete = $promotion->linkedContentPageTags();
        });
    }

    /**
     * ISR tags: `promotion:{id}` itself (kept for any fetch that ever tags
     * directly against it, e.g. a promo badge/banner component outside a
     * landing page) plus a **reverse lookup** of every `ContentPage` that
     * references this promotion via `promotion_id`, tagged with each page's
     * own `content:{type}:{slug}` tag directly.
     *
     * This is a deliberate correction, not the original design: a
     * promo-landing `ContentPage` tagging its own fetch with a *second*,
     * dependent `promotion:{id}` fetch once the linked promotion's id is
     * known does not reliably retag the page's already-cached primary
     * fetch under Next.js's actual fetch-cache behaviour — see
     * docs/architecture/02-api-contract.md's ISR webhook section (updated
     * 2026-09-24). Looking the pages up here and dispatching their own
     * `content:{type}:{slug}` tag directly closes the gap structurally,
     * since that tag is guaranteed to already be attached to the page's
     * primary fetch from its very first render (`ContentPage::
     * revalidationTags()` fires unconditionally on every save). Fires only
     * on update/delete, unconditionally (no field-relevance guard — every
     * column on this model is pricing/campaign-relevant to whatever
     * promo-landing copy references it).
     *
     * `deleted` reuses the pre-delete snapshot (`$contentPageTagsBeforeDelete`,
     * populated by the `deleting` hook above) rather than querying live —
     * see that property's docblock for why a live query here would always
     * come back empty. `updated` queries live since nothing nulls the FK on
     * an update.
     *
     * No `default => throw` arm — see `Brand::revalidationTags()`'s
     * docblock for why (this 3-arm match is provably exhaustive, unlike the
     * genuinely-external-input case this project's usual exhaustive-enum
     * convention targets).
     *
     * @param  'created'|'updated'|'deleted'  $event
     * @return list<string>
     */
    public function revalidationTags(string $event): array
    {
        return match ($event) {
            'created' => [],
            'updated' => ["promotion:{$this->id}", ...$this->linkedContentPageTags()],
            'deleted' => ["promotion:{$this->id}", ...($this->contentPageTagsBeforeDelete ?? $this->linkedContentPageTags())],
        };
    }

    /**
     * Every `ContentPage` currently linked to this promotion via
     * `promotion_id`, as `content:{type}:{slug}` tags. See
     * `revalidationTags()`'s docblock for why `deleted` cannot call this
     * live and must use the `deleting`-time snapshot instead.
     *
     * @return list<string>
     */
    private function linkedContentPageTags(): array
    {
        return array_values(
            ContentPage::query()
                ->where('promotion_id', $this->id)
                ->orderBy('id')
                ->get(['id', 'type', 'slug'])
                ->map(fn (ContentPage $page): string => "content:{$page->type->value}:{$page->slug}")
                ->all()
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'value' => 'integer',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'usage_limit' => 'integer',
            'usage_count' => 'integer',
            'stock_limit' => 'integer',
            'stackable' => 'boolean',
            'is_public' => 'boolean',
            'status' => Status::class,
        ];
    }
}
