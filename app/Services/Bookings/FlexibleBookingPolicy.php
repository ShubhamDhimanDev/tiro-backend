<?php

namespace App\Services\Bookings;

/**
 * The flexible-booking option: the customer accepts any window during the
 * service day and, in return, gets a fixed discount.
 *
 * Capacity rule (the important part): flexible never reserves "the whole
 * day" and never bypasses the slot engine. `POST /bookings` with
 * `flexible: true` asks {@see SlotComputationService} for the day's candidate
 * slots (the exact list `GET /booking-slots` returns for that duration) and
 * assigns the first one for which a technician/van is still free, inside the
 * same per-technician lock and job-cap checks as a normal booking. The
 * booking therefore occupies a real technician window (with travel buffer)
 * and counts toward the van's `max_jobs_per_day` like any other. If no slot
 * on that day can be assigned the request fails with 409, same as a taken
 * slot. The customer-visible promise is only the operating-hours window;
 * the concrete `slot_start`/`slot_end` returned are the assigned window.
 */
class FlexibleBookingPolicy
{
    public function isAvailable(): bool
    {
        return (bool) config('bookings.flexible.enabled', true) && $this->discountCents() > 0;
    }

    public function discountCents(): int
    {
        return max(0, (int) config('bookings.flexible.discount_cents', 1000));
    }

    /**
     * Customer-facing offer label, e.g. "Flexible arrival: save $10".
     */
    public function label(): string
    {
        return 'Flexible arrival: save '.$this->formatDollars($this->discountCents());
    }

    /**
     * The label of the discount line in cart/order totals.
     */
    public function lineLabel(): string
    {
        return 'Flexible booking discount';
    }

    private function formatDollars(int $cents): string
    {
        return $cents % 100 === 0
            ? '$'.intdiv($cents, 100)
            : '$'.number_format($cents / 100, 2);
    }
}
