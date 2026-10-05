<?php

namespace App\Models;

use App\Contracts\RevalidatesFrontend;
use App\Enums\PageStatus;
use Database\Factories\FaqFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single FAQ entry, global/shared (`content_page_id = null`) or scoped to
 * one specific {@see ContentPage}'s own FAQ block. `"pdp"` is a reserved
 * `category` value the PDP's shared/global FAQ block filters on
 * (`GET /api/v1/content/faqs?category=pdp`). Reuses `ContentPage`'s own
 * {@see PageStatus} enum rather than a second near-identical one — see the
 * Phase 6 task brief.
 *
 * @property int $id
 * @property string $question
 * @property string $answer
 * @property string|null $category
 * @property int|null $content_page_id
 * @property int $sort_order
 * @property PageStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['question', 'answer', 'category', 'content_page_id', 'sort_order', 'status'])]
class Faq extends Model implements RevalidatesFrontend
{
    /** @use HasFactory<FaqFactory> */
    use HasFactory;

    /**
     * Get the page this FAQ is scoped to, if any (null = global/shared).
     *
     * @return BelongsTo<ContentPage, $this>
     */
    public function contentPage(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class);
    }

    /**
     * Scope to rows currently visible on the public read API —
     * `status = published`, no `published_at` scheduling concept on this
     * entity (unlike `ContentPage`).
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PageStatus::Published);
    }

    /**
     * ISR tags: the global FAQ tag, plus a category-scoped tag when
     * `category` is set, plus a page-scoped tag when `content_page_id` is
     * set. Fires unconditionally on create/update/delete.
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
            'created', 'updated', 'deleted' => array_values(array_filter([
                'content:faq',
                $this->category !== null ? "content:faq:{$this->category}" : null,
                $this->content_page_id !== null ? "content:faq:page:{$this->content_page_id}" : null,
            ])),
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
            'sort_order' => 'integer',
            'status' => PageStatus::class,
        ];
    }
}
