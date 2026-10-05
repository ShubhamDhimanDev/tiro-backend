<?php

namespace App\Listeners;

use App\Contracts\Notifications\LoggableNotification;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationLog;
use App\Services\Notifications\SmsSendResult;
use Illuminate\Contracts\Events\ShouldBeDiscovered;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * The ONLY place `NotificationLog` rows get written — see that model's
 * docblock. Subscribed to both `NotificationSent` and `NotificationFailed`
 * (registered in `AppServiceProvider::configureEventListeners()`, this
 * app's stated "no auto-discovery, every listener gets an explicit
 * `Event::listen()`" convention). Writes exactly one row per channel per
 * send attempt: `NotificationSender::sendToNotifiable()` (Laravel core)
 * dispatches exactly one of these two events per channel per notification —
 * never both — so there is no double-logging risk to guard against here the
 * way `ContentPageObserver`/`FaqObserver` had to for two independent
 * observers on the same model event.
 *
 * Implements {@see ShouldBeDiscovered} returning `false`: that stated
 * "no auto-discovery" convention turns out not to hold —
 * `Illuminate\Foundation\Application::configure()` calls `->withEvents()`
 * unconditionally (confirmed by reading its source; `bootstrap/app.php`
 * itself never calls it, but the `Application::configure()` chain it's
 * built on already has, before `bootstrap/app.php`'s own chain even runs),
 * which registers Laravel's zero-config event auto-discovery scanning
 * `app/Listeners` for any public `handle*`/`__invoke` method — this class
 * qualifies. Without this opt-out, `handle()` would ALSO get registered via
 * auto-discovery on top of the explicit `Event::listen()` calls below,
 * double-firing and writing two rows per send instead of one (confirmed via
 * this class's own test coverage before this fix landed). This same latent
 * double-registration likely also affects
 * `App\Listeners\MarkStaffEmailAsVerifiedOnPasswordReset` — harmless there
 * only because `markEmailAsVerified()` is idempotent, masking it; flagged
 * for a maintainer to fix separately rather than changed here (out of
 * scope for this phase, zero-unrelated-behavior-change).
 */
class LogNotificationDelivery implements ShouldBeDiscovered
{
    public static function shouldBeDiscovered(): bool
    {
        return false;
    }

    public function handle(NotificationSent|NotificationFailed $event): void
    {
        $notification = $event->notification;

        if (! $notification instanceof LoggableNotification) {
            return;
        }

        // Same "no routable recipient -> nothing to log" posture as
        // `App\Notifications\Channels\SmsChannel`'s own docblock — a
        // `Customer` with no/invalid mobile never gets an SMS attempt
        // logged, since none was actually made.
        $recipient = $event->notifiable->routeNotificationFor($event->channel, $notification);

        if (blank($recipient)) {
            return;
        }

        $subject = $notification->notificationLogSubject();

        NotificationLog::create([
            'notifiable_type' => $event->notifiable::class,
            'notifiable_id' => $event->notifiable->getKey(),
            'type' => $notification->notificationLogType(),
            'channel' => NotificationChannel::fromChannelName($event->channel),
            'status' => $this->statusFor($event),
            'recipient' => (string) $recipient,
            'provider_message_id' => $event instanceof NotificationSent ? $this->providerMessageId($event->response) : null,
            'error_message' => $event instanceof NotificationFailed ? $this->errorMessage($event->data) : null,
            'related_type' => $subject?->getMorphClass(),
            'related_id' => $subject?->getKey(),
        ]);
    }

    private function statusFor(NotificationSent|NotificationFailed $event): NotificationDeliveryStatus
    {
        return $event instanceof NotificationSent
            ? NotificationDeliveryStatus::Sent
            : NotificationDeliveryStatus::Failed;
    }

    /**
     * `Sent` means "accepted by the provider", never confirmed-delivered —
     * see `NotificationLog`'s docblock — so this only ever reads an id the
     * provider handed back synchronously, nothing more.
     */
    private function providerMessageId(mixed $response): ?string
    {
        if ($response instanceof SmsSendResult) {
            return $response->providerMessageId;
        }

        if ($response instanceof SentMessage) {
            return $response->getMessageId();
        }

        return null;
    }

    /**
     * `error_message` is the provider FAILURE REASON ONLY — see
     * `NotificationLog`'s migration docblock for why nothing richer (like
     * message content) ever lands here.
     *
     * @param  array<string, mixed>  $data
     */
    private function errorMessage(array $data): string
    {
        $exception = $data['exception'] ?? null;

        if ($exception instanceof Throwable) {
            return $exception->getMessage();
        }

        $message = $data['message'] ?? null;

        return is_string($message) ? $message : 'Notification delivery failed.';
    }
}
