<?php

namespace App\Enums;

/**
 * The originating review platform — a single case today
 * ({@see self::Google}, the Business Profile APIs' `accounts.locations.
 * reviews.list` endpoint, see `App\Console\Commands\SyncGoogleReviewsCommand`).
 * Deliberately NOT pre-built with a speculative `Facebook`/other case —
 * adding a second source later is a one-line addition to this enum plus a
 * new sync command, not a refactor.
 *
 * `Review.source` casts to this enum natively (`ReviewSource::from()`
 * throws `ValueError` on an unrecognized DB value on its own, no extra
 * parsing helper needed). If a future addition ever branches on this enum
 * with a `match` (e.g. a per-source sync strategy lookup), that `match`
 * must carry an exhaustive `default => throw` arm — this project's standing
 * convention for every small closed vocabulary sourced from outside this
 * enum's own definition (three prior real bugs came from a non-exhaustive
 * `match(true)`, caught mechanically by phpstan each time) — see
 * `App\Enums\NotificationChannel::fromChannelName()` for the shape. A
 * `match ($this)` that already enumerates every current case (like
 * `App\Models\ContentPage::revalidationTags()`) is exempt from that rule:
 * phpstan itself flags a newly-added case missing from such a match, so a
 * `default` arm there would only mask that check.
 */
enum ReviewSource: string
{
    case Google = 'google';
}
