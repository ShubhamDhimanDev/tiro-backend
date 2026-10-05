<?php

namespace App\Models;

use Database\Factories\NewsletterSubscriberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A storefront newsletter sign-up (single opt-in). The public API never
 * reveals whether an address was already subscribed.
 *
 * @property int $id
 * @property string $email
 * @property string|null $first_name
 * @property string $source
 * @property Carbon $subscribed_at
 * @property Carbon|null $unsubscribed_at
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['email', 'first_name', 'source', 'subscribed_at', 'unsubscribed_at', 'ip_address'])]
class NewsletterSubscriber extends Model
{
    /** @use HasFactory<NewsletterSubscriberFactory> */
    use HasFactory;

    /**
     * Subscribe (or re-subscribe) an address. Existing rows keep their first
     * name unless a new one is supplied; an unsubscribed address is reactivated.
     */
    public static function subscribe(string $email, ?string $firstName, string $source, ?string $ipAddress): self
    {
        $subscriber = self::query()->firstOrNew(['email' => strtolower(trim($email))]);

        if (! $subscriber->exists) {
            $subscriber->source = $source;
            $subscriber->ip_address = $ipAddress;
        }

        if ($firstName !== null && $firstName !== '') {
            $subscriber->first_name = $firstName;
        }

        if (! $subscriber->exists || $subscriber->unsubscribed_at !== null) {
            $subscriber->subscribed_at = now();
            $subscriber->unsubscribed_at = null;
        }

        $subscriber->save();

        return $subscriber;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }
}
