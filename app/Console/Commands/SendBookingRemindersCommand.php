<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Notifications\BookingReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Day-before-appointment reminder trigger — a scheduled command, not a
 * `Booking` model-event observer, since "one day before" is a time-based
 * condition with nothing in `Booking`'s `updated` lifecycle to hook (see
 * `App\Observers\BookingNotificationObserver`'s docblock, which explicitly
 * does NOT wire `App\Notifications\BookingReminder`). Registered on a daily
 * `Australia/Melbourne`-pinned schedule in routes/console.php — see that
 * entry's comment for why the timezone pin matters
 * (`config('app.timezone')` is UTC).
 *
 * Single AU-wide timezone, hardcoded — every currently active
 * `ServiceZone` is VIC and there is no `ServiceZone.timezone` column to key
 * off even if it mattered right now. Cheap to revisit (add the column,
 * group this query by it) if a non-VIC state ever goes live.
 *
 * Idempotency: a `NotificationLog` existence check, not a new
 * `Booking.reminder_sent_at` column — `NotificationLog` is this app's one
 * delivery-record mechanism, not a column-per-feature (see that model's
 * docblock). Any row at all (`queued`/`sent`/`failed`) counts as "already
 * attempted" for a given booking — no auto-retry of a failed reminder on
 * the next run, matching this project's standing manual-retry posture for
 * delivery failures (same spirit as `queue:prune-failed`'s 48h window).
 * `->withoutOverlapping()` on the schedule entry is defense-in-depth against
 * a double-run, not the primary idempotency mechanism.
 *
 * Precision: date-only, not slot-aware — `scheduled_date = tomorrow`
 * (AU-local calendar date) is sufficient; a 6am-slot customer getting their
 * reminder the evening before (per the 17:00 schedule) is the expected
 * normal case, not an edge case.
 */
#[Signature('bookings:send-reminders')]
#[Description('Send the day-before-appointment reminder for every confirmed booking scheduled tomorrow (AU-local), skipping any already attempted')]
class SendBookingRemindersCommand extends Command
{
    public function handle(): int
    {
        $tomorrow = Carbon::tomorrow('Australia/Melbourne')->toDateString();

        $alreadyAttempted = NotificationLog::query()
            ->where('related_type', Booking::class)
            ->where('type', 'booking.reminder')
            ->pluck('related_id');

        $bookings = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereDate('scheduled_date', $tomorrow)
            ->whereNotIn('id', $alreadyAttempted)
            ->with('customer', 'order.customer')
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            $customer = $booking->customer ?? $booking->order?->customer;

            if ($customer === null) {
                continue;
            }

            $customer->notify(new BookingReminder($booking));
            $sent++;
        }

        $this->info("Dispatched {$sent} booking reminder(s) for {$tomorrow}.");

        return self::SUCCESS;
    }
}
