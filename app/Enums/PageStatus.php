<?php

namespace App\Enums;

use App\Models\ContentPage;
use App\Models\Faq;

/**
 * Shared publish-lifecycle status for {@see ContentPage} and
 * {@see Faq} — one vocabulary for both rather than two
 * near-identical enums (the Phase 6 task brief is explicit that `Faq` reuses
 * this enum, not a second one). Distinct from `App\Enums\Status` (the
 * catalogue/location "admin lifecycle" vocabulary of
 * draft/active/inactive/archived) — this one is publish-specific
 * (draft/published/archived) and pairs with `published_at` for scheduling,
 * which `Status` has no equivalent of.
 *
 * Visibility rule (enforced by every public read path, never left to the
 * caller): `status = Published AND (published_at IS NULL OR published_at <=
 * now())`. A `Draft` row is never publicly visible regardless of
 * `published_at`. A `Published` row with a future `published_at` is
 * scheduled, not yet live. `Archived` is a soft-retire (unpublish without
 * deleting, preserves audit-log history / allows republish), distinct from a
 * hard delete.
 */
enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
