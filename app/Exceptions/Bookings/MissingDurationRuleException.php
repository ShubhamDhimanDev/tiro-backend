<?php

namespace App\Exceptions\Bookings;

use App\Enums\DurationRuleAppliesTo;
use RuntimeException;

/**
 * Thrown when a booking's duration calculation invokes a `(applies_to, key)`
 * `DurationRule` combination with no matching seeded row — a hard
 * configuration error, never silently treated as 0 minutes. Left
 * unhandled/unrendered deliberately: the default exception handler logs it
 * and (via `shouldRenderJsonWhen`) renders a `500` JSON response for API
 * requests, which is exactly the documented behavior — see
 * docs/architecture/04-booking-capacity-engine.md's duration algorithm.
 */
class MissingDurationRuleException extends RuntimeException
{
    public function __construct(public readonly DurationRuleAppliesTo $appliesTo, public readonly string $key)
    {
        parent::__construct("Missing DurationRule for applies_to=\"{$appliesTo->value}\", key=\"{$key}\". Seed this combination before this booking configuration can be used.");
    }
}
