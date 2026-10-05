<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Listeners\LogNotificationDelivery;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One immutable row per channel per notification-delivery attempt — written
 * ONLY by {@see LogNotificationDelivery}, see that class's
 * docblock. Deliberately stores no message subject/body/order total/address
 * — metadata only, the locked structural answer to Phase 7's PII-leakage
 * security review; do not add a "just in case" field here.
 *
 * `created_at` only — no `updated_at` (a delivery-attempt fact is never
 * mutated after the fact, only ever superseded by a new row).
 *
 * @property int $id
 * @property class-string<Model> $notifiable_type
 * @property int $notifiable_id
 * @property string $type
 * @property NotificationChannel $channel
 * @property NotificationDeliveryStatus $status
 * @property string $recipient
 * @property string|null $provider_message_id
 * @property string|null $error_message
 * @property class-string<Model>|null $related_type
 * @property int|null $related_id
 * @property Carbon|null $created_at
 */
#[Fillable([
    'notifiable_type', 'notifiable_id', 'type', 'channel', 'status', 'recipient',
    'provider_message_id', 'error_message', 'related_type', 'related_id',
])]
class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    /**
     * No `updated_at` column exists — see this model's docblock.
     */
    public const UPDATED_AT = null;

    /**
     * Get the recipient this notification was sent to — `Customer` for
     * every event this phase.
     *
     * @return MorphTo<Model, $this>
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the row this notification was about, e.g. a `Booking`.
     *
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationDeliveryStatus::class,
        ];
    }
}
