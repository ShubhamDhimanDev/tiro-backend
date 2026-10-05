<?php

namespace App\Enums;

use App\Listeners\LogNotificationDelivery;

/**
 * `NotificationLog.status` — see docs/architecture (Phase 7 readiness
 * pass) and {@see LogNotificationDelivery}, the only writer
 * of this column. `Sent` means "accepted by the provider" (Resend/
 * MessageMedia returned success), not confirmed-delivered — there is no
 * delivery-webhook ingestion this phase, see `NotificationLog`'s docblock.
 */
enum NotificationDeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
