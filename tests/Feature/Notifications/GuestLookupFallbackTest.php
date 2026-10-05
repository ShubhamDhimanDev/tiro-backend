<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingCompleted;
use App\Notifications\BookingConfirmed;
use App\Notifications\BookingReminder;
use App\Notifications\BookingRescheduled;

/**
 * Covers docs/architecture/06-open-decisions.md item 16's still-open
 * "notification fallback for a closed replay window" gap: a guest who never
 * registered an account has no way to recover `Booking.manage_token`/
 * `Order.order_token` once its 15-minute one-time-secret replay window
 * closes. This is the minimum-viable mitigation (a support contact line, not
 * the deferred full token-reissuance fix) — every guest-facing booking
 * notification must surface a way to ask for help finding their booking
 * again.
 */
it('includes a guest lookup fallback line in BookingConfirmed mail and sms content', function () {
    config(['mail.from.address' => 'hello@tiro.test']);

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $mail = (new BookingConfirmed($booking))->toMail($customer);
    expect(implode(' ', $mail->introLines))->toContain("reply to it and we'll help you look up your booking");

    $sms = (new BookingConfirmed($booking))->toSms($customer);
    expect($sms->content)->toContain('hello@tiro.test')
        ->toContain("we'll help you look it up");
});

it('includes a guest lookup fallback line in BookingReminder mail and sms content', function () {
    config(['mail.from.address' => 'hello@tiro.test']);

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $mail = (new BookingReminder($booking))->toMail($customer);
    expect(implode(' ', $mail->introLines))->toContain("reply to this email and we'll help you look up your booking");

    $sms = (new BookingReminder($booking))->toSms($customer);
    expect($sms->content)->toContain('hello@tiro.test')
        ->toContain("we'll help you look it up");
});

it('includes a guest lookup fallback line in BookingRescheduled mail content', function () {
    $customer = Customer::factory()->create();
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $mail = (new BookingRescheduled($booking))->toMail($customer);

    expect(implode(' ', $mail->introLines))->toContain("reply to this email and we'll help you look it up");
});

it('includes a guest lookup fallback line in BookingCancelled mail content', function () {
    $customer = Customer::factory()->create();
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $mail = (new BookingCancelled($booking))->toMail($customer);

    expect(implode(' ', $mail->introLines))->toContain("reply to this email and we'll help you look it up");
});

it('includes a guest lookup fallback line in BookingCompleted mail content', function () {
    $customer = Customer::factory()->create();
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $mail = (new BookingCompleted($booking))->toMail($customer);

    expect(implode(' ', $mail->introLines))->toContain("reply to this email and we'll help you look it up");
});
