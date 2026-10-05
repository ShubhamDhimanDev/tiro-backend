<?php

use App\Contracts\Notifications\SmsProvider;
use App\Models\Booking;
use App\Models\Customer;
use App\Notifications\BookingConfirmed;
use App\Notifications\Channels\SmsChannel;
use App\Services\Notifications\SmsSendResult;

/**
 * `App\Notifications\Channels\SmsChannel` — mirrors
 * `Illuminate\Notifications\Channels\MailChannel`'s two conventions exactly,
 * see that class's docblock.
 */
it('calls the bound sms provider with the routed number and message content', function () {
    $customer = Customer::factory()->make(['mobile' => '+61491570156']);
    $booking = Booking::factory()->make();

    $provider = new class implements SmsProvider
    {
        public ?string $to = null;

        public ?string $body = null;

        public function send(string $to, string $body): SmsSendResult
        {
            $this->to = $to;
            $this->body = $body;

            return new SmsSendResult(success: true, providerMessageId: 'msg-1');
        }
    };

    $channel = new SmsChannel($provider);
    $result = $channel->send($customer, new BookingConfirmed($booking));

    expect($provider->to)->toBe('+61491570156');
    expect($provider->body)->not->toBeEmpty();
    expect($result)->toBeInstanceOf(SmsSendResult::class);
    expect($result->providerMessageId)->toBe('msg-1');
});

it('no-ops without calling the provider when there is no routable number', function () {
    $customer = Customer::factory()->make(['mobile' => null]);
    $booking = Booking::factory()->make();

    $provider = new class implements SmsProvider
    {
        public bool $called = false;

        public function send(string $to, string $body): SmsSendResult
        {
            $this->called = true;

            return new SmsSendResult(success: true);
        }
    };

    $channel = new SmsChannel($provider);
    $result = $channel->send($customer, new BookingConfirmed($booking));

    expect($provider->called)->toBeFalse();
    expect($result)->toBeNull();
});

it('throws when the provider reports a failure, so the caller can dispatch NotificationFailed', function () {
    $customer = Customer::factory()->make(['mobile' => '+61491570156']);
    $booking = Booking::factory()->make();

    $provider = new class implements SmsProvider
    {
        public function send(string $to, string $body): SmsSendResult
        {
            return new SmsSendResult(success: false, error: 'Provider unavailable');
        }
    };

    $channel = new SmsChannel($provider);

    expect(fn () => $channel->send($customer, new BookingConfirmed($booking)))
        ->toThrow(RuntimeException::class, 'Provider unavailable');
});
