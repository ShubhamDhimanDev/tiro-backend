<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\SmsProvider;
use App\Listeners\LogNotificationDelivery;
use App\Notifications\Channels\SmsChannel;

/**
 * A provider-agnostic view of a single SMS send attempt — decouples
 * {@see SmsChannel} and
 * {@see LogNotificationDelivery} from any given
 * {@see SmsProvider} implementor's raw
 * response shape, same reasoning as `App\Services\Payments\PaymentIntentResult`
 * decouples payment call sites from the raw Stripe SDK object.
 */
final class SmsSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {}
}
