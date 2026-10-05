<?php

namespace App\Models;

use App\Enums\DurationRuleAppliesTo;
use App\Enums\Status;
use App\Exceptions\Bookings\MissingDurationRuleException;
use Database\Factories\DurationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Admin-tunable job-length config — never hardcode a duration, see
 * docs/architecture/01-data-model.md's `DurationRule` section for the fixed
 * `(applies_to, key)` vocabulary table and
 * docs/architecture/04-booking-capacity-engine.md for the formula this
 * feeds.
 *
 * @property int $id
 * @property DurationRuleAppliesTo $applies_to
 * @property string $key
 * @property int $minutes
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['applies_to', 'key', 'minutes', 'status'])]
class DurationRule extends Model
{
    /** @use HasFactory<DurationRuleFactory> */
    use HasFactory;

    /**
     * Resolve the minutes for a `(applies_to, key)` combination.
     *
     * A missing row is a **hard error at booking-creation time** (logged
     * here, rendered as a 500 by the default exception handler), never a
     * silent `0` — an under-reserved technician window is a much worse,
     * customer-visible failure mode than a loud config error caught in
     * staging. See docs/architecture/04-booking-capacity-engine.md's
     * duration algorithm.
     *
     * @throws MissingDurationRuleException
     */
    public static function minutesFor(DurationRuleAppliesTo $appliesTo, string $key): int
    {
        $rule = static::query()
            ->where('applies_to', $appliesTo)
            ->where('key', $key)
            ->where('status', Status::Active)
            ->first();

        if ($rule === null) {
            Log::error('Missing DurationRule for booking duration calculation.', [
                'applies_to' => $appliesTo->value,
                'key' => $key,
            ]);

            throw new MissingDurationRuleException($appliesTo, $key);
        }

        return $rule->minutes;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applies_to' => DurationRuleAppliesTo::class,
            'minutes' => 'integer',
            'status' => Status::class,
        ];
    }
}
