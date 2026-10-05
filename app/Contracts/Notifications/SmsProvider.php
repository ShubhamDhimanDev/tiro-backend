<?php

namespace App\Contracts\Notifications;

use App\Notifications\Channels\SmsChannel;
use App\Services\Notifications\MessageMediaClient;
use App\Services\Notifications\SmsSendResult;

/**
 * The SMS-sending boundary {@see SmsChannel}
 * codes against — never a provider SDK/HTTP client directly. Same reasoning
 * as `App\Contracts\Payments\PaymentGateway`: keeps every SMS call site
 * testable without live provider credentials (bind a fake implementation in
 * tests), and gives a future second provider (Twilio — explicitly not built
 * this phase, see {@see MessageMediaClient}'s
 * docblock) a second implementor to slot in later without touching call
 * sites.
 */
interface SmsProvider
{
    /**
     * Send a single SMS `$body` to `$to` (E.164 phone number).
     */
    public function send(string $to, string $body): SmsSendResult;
}
