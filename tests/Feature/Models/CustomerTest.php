<?php

use App\Models\Customer;

/**
 * `Customer::routeNotificationForSms()` — Phase 7. Returns the mobile
 * number only when it's a valid E.164 string; `null` otherwise, so Laravel's
 * `sms` channel no-ops rather than attempting to send to an unusable value
 * (see `App\Notifications\Channels\SmsChannel`'s docblock).
 */
it('routes sms notifications to a valid e164 mobile number', function () {
    $customer = Customer::factory()->make(['mobile' => '+61491570156']);

    expect($customer->routeNotificationForSms())->toBe('+61491570156');
});

it('returns null when the mobile is empty', function () {
    $customer = Customer::factory()->make(['mobile' => null]);

    expect($customer->routeNotificationForSms())->toBeNull();
});

it('returns null when the mobile is not in e164 shape', function () {
    $customer = Customer::factory()->make(['mobile' => '0491570156']);

    expect($customer->routeNotificationForSms())->toBeNull();
});

it('falls back to the email column for mail routing without an explicit override', function () {
    $customer = Customer::factory()->make(['email' => 'test@example.com']);

    expect($customer->routeNotificationFor('mail'))->toBe('test@example.com');
});
