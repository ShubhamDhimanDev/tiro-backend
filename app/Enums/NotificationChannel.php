<?php

namespace App\Enums;

use App\Listeners\LogNotificationDelivery;
use RuntimeException;

/**
 * `NotificationLog.channel` — see docs/architecture (Phase 7 readiness
 * pass). Every `App\Notifications\*` class this phase declares its `via()`
 * channels using Laravel's own `'mail'`/`'sms'` string names; this enum is
 * how {@see LogNotificationDelivery} records which one a
 * given send used.
 *
 * Fragile-pattern note: parse a raw channel-name string with
 * {@see fromChannelName()} (never a bare string comparison) — same
 * exhaustive `default => throw` convention as `VehicleFitmentPosition`/
 * `VehicleFitmentConfidence` (see those enums' docblocks for the bug
 * history that made this a standing rule).
 */
enum NotificationChannel: string
{
    case Mail = 'mail';
    case Sms = 'sms';

    /**
     * Resolve the channel name Laravel's notification system itself uses
     * (the string returned by a Notification's `via()` array, and the
     * `$channel` argument `NotificationSent`/`NotificationFailed` events
     * carry) onto this enum.
     */
    public static function fromChannelName(string $channel): self
    {
        return match ($channel) {
            'mail' => self::Mail,
            'sms' => self::Sms,
            default => throw new RuntimeException("Unhandled notification channel \"{$channel}\"."),
        };
    }
}
