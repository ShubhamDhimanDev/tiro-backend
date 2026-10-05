<?php

namespace App\Contracts\Notifications;

use App\Listeners\LogNotificationDelivery;
use Illuminate\Database\Eloquent\Model;

/**
 * Implemented by every `App\Notifications\*` class this phase so
 * {@see LogNotificationDelivery} can record a
 * `NotificationLog` row without a per-notification-class `match()` (and so
 * it safely ignores any *other* notification firing through this app's
 * global `NotificationSent`/`NotificationFailed` events — e.g. Fortify's
 * own password-reset notification for staff `User` rows — since those don't
 * implement this interface).
 */
interface LoggableNotification
{
    /**
     * The dotted-namespace `NotificationLog.type` value, e.g.
     * `booking.confirmed` — see `NotificationLog`'s migration for the
     * exhaustive list this phase.
     */
    public function notificationLogType(): string;

    /**
     * The row this notification is about, for `NotificationLog`'s
     * `related_type`/`related_id` polymorphic columns — `Booking` for every
     * notification this phase.
     */
    public function notificationLogSubject(): ?Model;
}
