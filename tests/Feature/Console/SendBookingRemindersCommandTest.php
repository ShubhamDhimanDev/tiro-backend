<?php

use App\Enums\BookingStatus;
use App\Enums\NotificationChannel;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Notifications\BookingReminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * `php artisan bookings:send-reminders` — the day-before-appointment
 * reminder trigger, see `App\Console\Commands\SendBookingRemindersCommand`'s
 * docblock. `BookingReminder` is NOT dispatched by
 * `App\Observers\BookingNotificationObserver` — this command is its only
 * trigger.
 */
function tomorrowAuLocal(): string
{
    return Carbon::tomorrow('Australia/Melbourne')->toDateString();
}

it('dispatches a reminder for a confirmed booking scheduled tomorrow', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'scheduled_date' => tomorrowAuLocal(),
    ]);

    $this->artisan('bookings:send-reminders')->assertExitCode(0);

    Notification::assertSentTo($customer, BookingReminder::class, fn ($notification) => $notification->booking->is($booking));
});

it('skips a booking that is not confirmed', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    Booking::factory()->create([
        'customer_id' => $customer->id,
        'status' => BookingStatus::PendingHold,
        'scheduled_date' => tomorrowAuLocal(),
    ]);

    $this->artisan('bookings:send-reminders')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('skips a confirmed booking not scheduled for tomorrow', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    Booking::factory()->create([
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'scheduled_date' => Carbon::tomorrow('Australia/Melbourne')->addDays(3)->toDateString(),
    ]);

    $this->artisan('bookings:send-reminders')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('does not dispatch a second reminder for a booking already attempted (idempotency)', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'scheduled_date' => tomorrowAuLocal(),
    ]);

    NotificationLog::factory()->create([
        'notifiable_type' => Customer::class,
        'notifiable_id' => $customer->id,
        'type' => 'booking.reminder',
        'related_type' => Booking::class,
        'related_id' => $booking->id,
    ]);

    $this->artisan('bookings:send-reminders')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('still dispatches once for a booking with an unrelated notification log row', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'scheduled_date' => tomorrowAuLocal(),
    ]);

    NotificationLog::factory()->create([
        'notifiable_type' => Customer::class,
        'notifiable_id' => $customer->id,
        'type' => 'booking.confirmed',
        'related_type' => Booking::class,
        'related_id' => $booking->id,
    ]);

    $this->artisan('bookings:send-reminders')->assertExitCode(0);

    Notification::assertSentTo($customer, BookingReminder::class);
});

it('falls back to the linked order\'s customer for a guest booking', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create([
        'customer_id' => null,
        'status' => BookingStatus::Confirmed,
        'scheduled_date' => tomorrowAuLocal(),
    ]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'booking_id' => $booking->id]);
    $booking->forceFill(['order_id' => $order->id])->save();

    $this->artisan('bookings:send-reminders')->assertExitCode(0);

    Notification::assertSentTo($customer, BookingReminder::class);
});

/**
 * Coverage-gap fill (phase test-scope item 3): the tests above prove the
 * idempotency *check* (an existing `NotificationLog` row causes a skip) by
 * pre-seeding that row directly via the factory. This test instead proves
 * the real end-to-end consequence the phase spec asks for: running the
 * actual command twice in succession for the same booking — with real
 * `Customer::notify()` -> `LogNotificationDelivery` writes, not
 * `Notification::fake()` — must leave exactly one `NotificationLog` row
 * behind, not two. `mobile` is left blank so only the mail leg (safe under
 * `MAIL_MAILER=array`) is attempted, keeping this focused on the log-count
 * assertion rather than sms-provider plumbing (already covered elsewhere).
 */
it('running the command twice for the same booking writes only one NotificationLog row, not two', function () {
    $customer = Customer::factory()->create(['mobile' => null]);
    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'scheduled_date' => tomorrowAuLocal(),
    ]);

    $this->artisan('bookings:send-reminders')->assertExitCode(0);
    $this->artisan('bookings:send-reminders')->assertExitCode(0);

    $rows = NotificationLog::query()
        ->where('related_type', Booking::class)
        ->where('related_id', $booking->id)
        ->where('type', 'booking.reminder')
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->channel)->toBe(NotificationChannel::Mail);
});

it('skips a booking with no resolvable customer without erroring', function () {
    Notification::fake();

    Booking::factory()->create([
        'customer_id' => null,
        'order_id' => null,
        'status' => BookingStatus::Confirmed,
        'scheduled_date' => tomorrowAuLocal(),
    ]);

    $this->artisan('bookings:send-reminders')->assertExitCode(0);

    Notification::assertNothingSent();
});
