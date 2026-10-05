<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/reviews` item shape — see the Phase 8 task brief's "Public
 * API" section. `is_hidden` is never exposed here (nor is `location_id`,
 * not part of the documented public shape) — a hidden review's
 * non-existence to the public API is indistinguishable from it never
 * having synced, matching every other public content endpoint's posture in
 * this project.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'author_name' => $this->author_name,
            'author_photo_url' => $this->author_photo_url,
            'rating' => $this->rating,
            'body' => $this->body,
            'reply_body' => $this->reply_body,
            'review_url' => $this->review_url,
            'published_at' => $this->published_at->toIso8601String(),
        ];
    }
}
