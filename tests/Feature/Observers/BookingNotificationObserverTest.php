<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Order;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingCompleted;
use App\Notifications\BookingConfirmed;
use App\Notifications\BookingRescheduled;
use Illuminate\Support\Facades\Notification;

/**
 * `App\Observers\BookingNotificationObserver`, registered on `Booking`'s
 * `updated` event in `AppServiceProvider::configureObservers()`. Does NOT
 * cover `BookingReminder` — that's a time-based trigger, see
 * `App\Console\Commands\SendBookingRemindersCommand`'s own test instead.
 */
it('dispatches BookingConfirmed when status transitions pending_hold -> confirmed', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create(['customer_id' => $customer->id, 'status' => BookingStatus::PendingHold]);

    $booking->forceFill(['status' => BookingStatus::Confirmed])->save();

    Notification::assertSentTo($customer, BookingConfirmed::class, fn ($notification) => $notification->booking->is($booking));
});

it('dispatches BookingCancelled when status transitions to cancelled', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

    Notification::assertSentTo($customer, BookingCancelled::class);
});

it('dispatches BookingCompleted when status transitions to completed', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $booking->forceFill(['status' => BookingStatus::Completed])->save();

    Notification::assertSentTo($customer, BookingCompleted::class);
});

it('dispatches BookingRescheduled when scheduled_date changes on a confirmed booking', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $booking->forceFill(['scheduled_date' => now()->addDays(5)->toDateString()])->save();

    Notification::assertSentTo($customer, BookingRescheduled::class);
});

it('dispatches BookingRescheduled when slot_start changes on a confirmed booking', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id, 'slot_start' => '09:00:00', 'slot_end' => '09:45:00']);

    $booking->forceFill(['slot_start' => '10:00:00', 'slot_end' => '10:45:00'])->save();

    Notification::assertSentTo($customer, BookingRescheduled::class);
});

it('does not dispatch BookingRescheduled for a slot change on a still-pending_hold booking', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create(['customer_id' => $customer->id, 'status' => BookingStatus::PendingHold]);

    $booking->forceFill(['slot_start' => '10:00:00', 'slot_end' => '10:45:00'])->save();

    Notification::assertNothingSent();
});

it('falls back to the linked order\'s customer for a guest booking', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create(['customer_id' => null, 'status' => BookingStatus::PendingHold]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'booking_id' => $booking->id]);
    $booking->forceFill(['order_id' => $order->id])->save();

    $booking->forceFill(['status' => BookingStatus::Confirmed])->save();

    Notification::assertSentTo($customer, BookingConfirmed::class);
});

it('does not dispatch anything when neither the booking nor its order has a customer', function () {
    Notification::fake();

    $booking = Booking::factory()->create(['customer_id' => null, 'order_id' => null, 'status' => BookingStatus::PendingHold]);

    $booking->forceFill(['status' => BookingStatus::Confirmed])->save();

    Notification::assertNothingSent();
});
