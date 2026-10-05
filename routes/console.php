<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Defense in depth for docs/architecture/06-open-decisions.md item 14: even
 * with `OtpCodeMail` now `ShouldBeEncrypted`, nothing should linger forever
 * in `failed_jobs`. 48 hours is enough time to notice and manually retry a
 * genuinely failed OTP delivery in this local-dev/early-stage app, while
 * still bounding how long any (encrypted) auth-adjacent payload sits at
 * rest.
 */
Schedule::command('queue:prune-failed', ['--hours' => 48])->daily();

/**
 * Safety-net sweep for docs/architecture/04-booking-capacity-engine.md's
 * hold-with-TTL mechanism — catches any hold whose delayed release job never
 * fired. Runs alongside (not instead of) the per-hold
 * `ReleaseExpiredBookingHold` delayed job dispatched at hold-creation time;
 * both call the same `releaseHold()` model method.
 */
Schedule::command('bookings:release-expired-holds')->everyMinute();

/**
 * Day-before-appointment reminder — see
 * `App\Console\Commands\SendBookingRemindersCommand`'s docblock for the
 * idempotency mechanism (a `NotificationLog` existence check) and why the
 * timezone is explicitly pinned here rather than left to
 * `config('app.timezone')` (UTC): computing "tomorrow" against a UTC clock
 * rolls the calendar date over at ~10-11am AU local time (UTC midnight),
 * not AU local midnight — left un-pinned, this would silently misdate
 * bookings near that boundary, the one schedule entry in this file where it
 * actually matters (`queue:prune-failed`/`bookings:release-expired-holds`
 * above don't care what local time they run at).
 */
Schedule::command('bookings:send-reminders')->timezone('Australia/Melbourne')->dailyAt('17:00')->withoutOverlapping();

/**
 * Daily Google Business Profile reviews sync — see
 * `App\Console\Commands\SyncGoogleReviewsCommand`'s docblock. No
 * `->timezone()` pin needed, unlike the reminders entry above: nothing
 * here computes a calendar-date boundary, "roughly 3am server time, once a
 * day" is sufficient precision.
 */
Schedule::command('reviews:sync-google')->dailyAt('03:00')->withoutOverlapping();
