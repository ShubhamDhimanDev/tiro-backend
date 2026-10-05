<?php

namespace App\Http\Resources;

use App\Models\ContentPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/content/pages/{type}/{slug}` single-item shape — adds `body`
 * and `og_image_path` over {@see ContentPageSummaryResource}, plus a
 * minimal nested `service_zone`/`promotion` summary object when this page
 * links one. Both are omitted entirely (not even `null`) when unset, same
 * "don't fabricate a field" posture as `TyreVariantResource`'s
 * zone-dependent fields.
 *
 * @mixin ContentPage
 */
class ContentPageDetailResource extends JsonResource
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
            'body' => $this->body,
            'featured_image_path' => $this->featured_image_path,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'og_image_path' => $this->og_image_path,
            'category' => $this->category,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // `whenLoaded()` alone isn't enough here: it only checks whether
            // the relation was *queried*, not whether it resolved to a row
            // (a nullable belongsTo that's genuinely unset is still
            // "loaded", just loaded as null) — calling the closure against
            // a null relation would throw. Guard on both.
            'service_zone' => $this->when(
                $this->relationLoaded('serviceZone') && $this->serviceZone !== null,
                fn () => ['id' => $this->serviceZone->id, 'name' => $this->serviceZone->name],
            ),
            'promotion' => $this->when(
                $this->relationLoaded('promotion') && $this->promotion !== null,
                fn () => [
                    'id' => $this->promotion->id,
                    'name' => $this->promotion->name,
                    'type' => $this->promotion->type->value,
                    'value' => $this->promotion->value,
                    'starts_at' => $this->promotion->starts_at->toDateString(),
                    'ends_at' => $this->promotion->ends_at->toDateString(),
                ],
            ),
        ];
    }
}
