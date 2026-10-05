<?php

namespace App\Rules;

use App\Enums\VehicleFitmentConfidence;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * `POST|PATCH /api/v1/customer/vehicles`' `saved_fitment` body field — see
 * docs/architecture (Phase 7 readiness pass). Accepts either shape:
 *
 *  (a) an exact pass-through of `GET /api/v1/vehicles/{vehicle}/fitment`'s
 *      `fitments` field (when `vehicle_id` is set) — each position may carry
 *      the extra `confidence` string that endpoint returns; or
 *  (b) a customer-typed size — the same position keys, `width`/`profile`/
 *      `rim_diameter` required, `load_index`/`speed_rating` optional,
 *      `confidence` omitted.
 *
 * Both shapes are keyed `all` alone, or `front` + `rear` together —
 * never `all` mixed with either, and never just one of `front`/`rear` on
 * its own (an incomplete staggered set isn't a usable saved fitment).
 */
class SavedFitmentShape implements ValidationRule
{
    /**
     * @var list<string>
     */
    private const REQUIRED_SIZE_FIELDS = ['width', 'profile', 'rim_diameter'];

    /**
     * @var list<string>
     */
    private const OPTIONAL_SIZE_FIELDS = ['load_index', 'speed_rating', 'confidence'];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            $fail('The :attribute must be a non-empty object keyed by fitment position.');

            return;
        }

        $keys = array_keys($value);
        sort($keys);

        if ($keys !== ['all'] && $keys !== ['front', 'rear']) {
            $fail('The :attribute must be keyed by either "all", or both "front" and "rear".');

            return;
        }

        foreach ($value as $position => $size) {
            if (! $this->validSize($size)) {
                $fail("The :attribute.{$position} entry is not a valid fitment size.");

                return;
            }
        }
    }

    private function validSize(mixed $size): bool
    {
        if (! is_array($size)) {
            return false;
        }

        foreach (self::REQUIRED_SIZE_FIELDS as $field) {
            if (! isset($size[$field]) || ! is_int($size[$field])) {
                return false;
            }
        }

        $allowedKeys = [...self::REQUIRED_SIZE_FIELDS, ...self::OPTIONAL_SIZE_FIELDS];

        foreach (array_keys($size) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                return false;
            }
        }

        if (isset($size['load_index']) && ! is_string($size['load_index'])) {
            return false;
        }

        if (isset($size['speed_rating']) && ! is_string($size['speed_rating'])) {
            return false;
        }

        if (isset($size['confidence']) && VehicleFitmentConfidence::tryFrom((string) $size['confidence']) === null) {
            return false;
        }

        return true;
    }
}
