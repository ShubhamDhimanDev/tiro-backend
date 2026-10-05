<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Notifications\BookingCompleted;
use App\Notifications\BookingConfirmed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * `App\Listeners\LogNotificationDelivery` — the only place `NotificationLog`
 * rows get written. Exercised end-to-end (real `Customer::notify()`, real
 * channels) rather than unit-testing the listener in isolation, since the
 * behavior under test is genuinely "does the whole pipeline produce exactly
 * the right rows" — `MAIL_MAILER=array` (test env default) keeps the mail
 * channel safe, `Http::fake()` keeps the sms channel safe.
 */
it('writes one sent row per channel for a mail+sms notification', function () {
    Http::fake(['api.messagemedia.com/*' => Http::response([
        'messages' => [['message_id' => 'msg-abc', 'status' => 'queued']],
    ], 200)]);

    $customer = Customer::factory()->create(['mobile' => '+61491570156', 'email' => 'jane@example.com']);
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $customer->notify(new BookingConfirmed($booking));

    expect(NotificationLog::query()->count())->toBe(2);

    $mailRow = NotificationLog::query()->where('channel', NotificationChannel::Mail)->firstOrFail();
    expect($mailRow->notifiable_type)->toBe(Customer::class);
    expect($mailRow->notifiable_id)->toBe($customer->id);
    expect($mailRow->type)->toBe('booking.confirmed');
    expect($mailRow->status)->toBe(NotificationDeliveryStatus::Sent);
    expect($mailRow->recipient)->toBe('jane@example.com');
    expect($mailRow->related_type)->toBe(Booking::class);
    expect($mailRow->related_id)->toBe($booking->id);
    expect($mailRow->error_message)->toBeNull();

    $smsRow = NotificationLog::query()->where('channel', NotificationChannel::Sms)->firstOrFail();
    expect($smsRow->status)->toBe(NotificationDeliveryStatus::Sent);
    expect($smsRow->recipient)->toBe('+61491570156');
    expect($smsRow->provider_message_id)->toBe('msg-abc');
});

it('skips the sms row entirely when the customer has no routable mobile', function () {
    $customer = Customer::factory()->create(['mobile' => null]);
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $customer->notify(new BookingCompleted($booking));

    expect(NotificationLog::query()->count())->toBe(1);
    expect(NotificationLog::query()->first()->channel)->toBe(NotificationChannel::Mail);
});

it('writes a failed row with the provider error message when the sms provider fails', function () {
    Http::fake(['api.messagemedia.com/*' => Http::response(['message' => 'Unauthorized'], 401)]);

    $customer = Customer::factory()->create(['mobile' => '+61491570156']);
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    // The sms channel throws on a provider failure (see SmsChannel's
    // docblock) — under the sync queue driver used in tests, that
    // propagates back out of notify() after the NotificationFailed event
    // (and this listener) has already run, so it's caught here rather than
    // treated as a test failure.
    try {
        $customer->notify(new BookingConfirmed($booking));
    } catch (Throwable) {
        // expected — see comment above.
    }

    $smsRow = NotificationLog::query()->where('channel', NotificationChannel::Sms)->firstOrFail();
    expect($smsRow->status)->toBe(NotificationDeliveryStatus::Failed);
    expect($smsRow->error_message)->not->toBeNull();
    expect($smsRow->provider_message_id)->toBeNull();
});

/**
 * Coverage-gap fill (phase test-scope item 4): `BookingCompleted` — used by
 * the existing "skips the sms row entirely" case above — is mail-only
 * (`via()` never returns `sms`, see that class), so it never actually
 * exercises the sms-channel skip path. `BookingConfirmed` routes
 * `['mail', 'sms']` unconditionally; a blank `mobile` must still let the
 * whole `notify()` call complete cleanly (no exception, no failed-sms row),
 * with only the mail leg actually attempted.
 */
it('completes BookingConfirmed cleanly for a customer with no mobile — only the mail leg sends, no sms attempt at all', function () {
    $customer = Customer::factory()->create(['mobile' => null, 'email' => 'no-mobile@example.com']);
    $booking = Booking::factory()->confirmed()->create(['customer_id' => $customer->id]);

    $customer->notify(new BookingConfirmed($booking));

    expect(NotificationLog::query()->count())->toBe(1);
    $row = NotificationLog::query()->sole();
    expect($row->channel)->toBe(NotificationChannel::Mail);
    expect($row->status)->toBe(NotificationDeliveryStatus::Sent);
    expect($row->recipient)->toBe('no-mobile@example.com');
});

/**
 * Regression test for the Phase 7 auto-discovery finding (see this
 * listener's own docblock) — mirrors
 * `PasswordResetVerifiesStaffEmailTest`'s identical-purpose assertion for
 * `MarkStaffEmailAsVerifiedOnPasswordReset`. Proves the fix directly via
 * `getListeners()` rather than only inferring it from "exactly one row was
 * observed" in the tests above, which would still pass even if
 * `NotificationLog::create()` happened to be idempotent for some unrelated
 * reason.
 */
it('is registered for NotificationSent and NotificationFailed exactly once each, not twice via auto-discovery', function () {
    $dispatcher = app(Dispatcher::class);

    expect($dispatcher->getListeners(NotificationSent::class))->toHaveCount(1);
    expect($dispatcher->getListeners(NotificationFailed::class))->toHaveCount(1);
});

it('ignores notifications that do not implement LoggableNotification', function () {
    $customer = Customer::factory()->create();

    $customer->notifyNow(new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return new MailMessage;
        }
    });

    expect(NotificationLog::query()->count())->toBe(0);
});
