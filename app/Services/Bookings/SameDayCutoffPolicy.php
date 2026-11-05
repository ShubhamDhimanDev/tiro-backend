<?php

namespace App\Services\Bookings;

use Carbon\CarbonImmutable;

/**
 * The same-day booking rule: a fitting can be booked for today only if the
 * booking is made before the daily cutoff (default 09:00, business time).
 * After the cutoff the earliest bookable day is tomorrow, and the storefront
 * shows a "call us" prompt for same-day help instead.
 *
 * "Business time" is `bookings.timezone`, not the app's UTC clock: the
 * cutoff, the date a customer sees as "today" and slot start times are all
 * the fitting zone's local time. Slots that start sooner than
 * `bookings.same_day.min_lead_minutes` from now are also dropped on the
 * same-day path, so a van is never offered a job that starts in a few minutes.
 *
 * Applies to the customer-facing endpoints only (slot lists, create,
 * reschedule). Staff assigning from the admin dispatch board are not bound by it.
 */
class SameDayCutoffPolicy
{
    public function timezone(): string
    {
        return (string) config('bookings.timezone', 'Australia/Melbourne');
    }

    /** The cutoff as "HH:MM", e.g. "09:00". */
    public function cutoffTime(): string
    {
        return (string) config('bookings.same_day.cutoff', '09:00');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /** Whether a booking made right now can still be for today. */
    public function sameDayOpen(?CarbonImmutable $now = null): bool
    {
        $now = $this->local($now);

        return $this->minutesOfDay($now->format('H:i')) < $this->minutesOfDay($this->cutoffTime());
    }

    /** Today's date in business time ("Y-m-d"). */
    public function today(?CarbonImmutable $now = null): string
    {
        return $this->local($now)->toDateString();
    }

    /** The earliest date a customer can book ("Y-m-d"): today before the cutoff, otherwise tomorrow. */
    public function earliestDate(?CarbonImmutable $now = null): string
    {
        $now = $this->local($now);

        return ($this->sameDayOpen($now) ? $now : $now->addDay())->toDateString();
    }

    /**
     * Whether a customer may book `$slotStart` ("HH:MM") on `$date` ("Y-m-d").
     * Pass null for `$slotStart` to ask only about the day (e.g. a flexible booking).
     */
    public function allows(string $date, ?string $slotStart = null, ?CarbonImmutable $now = null): bool
    {
        $now = $this->local($now);
        $today = $now->toDateString();

        if ($date < $today) {
            return false;
        }

        if ($date > $today) {
            return true;
        }

        if (! $this->sameDayOpen($now)) {
            return false;
        }

        if ($slotStart === null) {
            return true;
        }

        $earliestStart = $this->minutesOfDay($now->format('H:i')) + max(0, (int) config('bookings.same_day.min_lead_minutes', 60));

        return $this->minutesOfDay($slotStart) >= $earliestStart;
    }

    /**
     * Drop the slots on `$date` that a customer cannot book.
     *
     * @param  list<array{start: string, end: string}>  $slots
     * @return list<array{start: string, end: string}>
     */
    public function filterSlots(string $date, array $slots, ?CarbonImmutable $now = null): array
    {
        $now = $this->local($now);

        return array_values(array_filter($slots, fn (array $slot): bool => $this->allows($date, $slot['start'], $now)));
    }

    /**
     * The rule in API form, so the storefront can explain it without
     * re-deriving the time maths.
     *
     * @return array{timezone: string, same_day_cutoff: string, same_day_open: bool, today: string, earliest_date: string}
     */
    public function rules(?CarbonImmutable $now = null): array
    {
        $now = $this->local($now);

        return [
            'timezone' => $this->timezone(),
            'same_day_cutoff' => $this->cutoffTime(),
            'same_day_open' => $this->sameDayOpen($now),
            'today' => $this->today($now),
            'earliest_date' => $this->earliestDate($now),
        ];
    }

    private function local(?CarbonImmutable $now): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone($this->timezone());
    }

    private function minutesOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
