<?php

namespace App\Notifications\Channels;

use App\Contracts\Notifications\SmsProvider;
use App\Notifications\Messages\SmsMessage;
use App\Services\Notifications\SmsSendResult;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Custom notification channel registered under the `'sms'` driver name (see
 * `AppServiceProvider::boot()`'s `Notification::extend('sms', ...)` call —
 * Laravel's documented mechanism for a channel name that isn't one of the
 * framework's built-ins). Every `via()` returning `'sms'` in this app routes
 * here, which in turn calls the bound {@see SmsProvider} singleton — never a
 * provider SDK/HTTP client directly, same boundary discipline as
 * `App\Contracts\Payments\PaymentGateway`.
 *
 * Mirrors `Illuminate\Notifications\Channels\MailChannel`'s own two
 * conventions exactly, so the framework's generic
 * `NotificationSender::sendToNotifiable()` behaves identically for both
 * channels without any special-casing there or in
 * `App\Listeners\LogNotificationDelivery`:
 *  1. No routable recipient -> return `null` without attempting a send (no
 *     exception, no provider call) — `NotificationSent` still fires with a
 *     `null` response, and the listener treats a blank recipient as "no
 *     attempt, nothing to log", never writing a row for it.
 *  2. A provider-reported failure -> throw, letting
 *     `NotificationSender`'s own catch-all dispatch exactly one
 *     `NotificationFailed` event (with `data.exception`) — not dispatched
 *     manually here, which would otherwise risk a duplicate `NotificationSent`
 *     firing right after (see that method's source: it only skips
 *     `NotificationSent` when `send()` throws).
 */
class SmsChannel
{
    public function __construct(private readonly SmsProvider $provider) {}

    public function send(mixed $notifiable, Notification $notification): ?SmsSendResult
    {
        if (! method_exists($notification, 'toSms')) {
            throw new RuntimeException(sprintf('Notification [%s] does not implement toSms().', $notification::class));
        }

        $to = $notifiable->routeNotificationFor('sms', $notification);

        if (blank($to)) {
            return null;
        }

        /** @var SmsMessage $message */
        $message = $notification->toSms($notifiable);

        $result = $this->provider->send($to, $message->content);

        if (! $result->success) {
            throw new RuntimeException($result->error ?? 'SMS send failed for an unknown reason.');
        }

        return $result;
    }
}
