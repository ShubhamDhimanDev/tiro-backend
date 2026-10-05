<?php

namespace App\Http\Resources;

use App\Models\ContentPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/content/pages` listing shape — no `body` (that's the detail
 * endpoint only, {@see ContentPageDetailResource}). `meta_title`/
 * `meta_description` are returned as-authored (possibly null); the "falls
 * back to title/excerpt when null" rule is a frontend-rendering concern, not
 * something this API resolves server-side.
 *
 * @mixin ContentPage
 */
class ContentPageSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'featured_image_path' => $this->featured_image_path,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'category' => $this->category,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
