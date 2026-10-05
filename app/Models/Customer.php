<?php

namespace App\Models;

use App\Notifications\Channels\SmsChannel;
use Database\Factories\CustomerFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $mobile
 * @property string|null $password
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'mobile', 'password'])]
#[Hidden(['password'])]
class Customer extends Model implements AuthenticatableContract, AuthorizableContract
{
    /** @use HasFactory<CustomerFactory> */
    use Authenticatable, Authorizable, HasApiTokens, HasFactory, Notifiable;

    /**
     * `routeNotificationForMail()` is deliberately NOT overridden here:
     * `Notifiable`'s own `RoutesNotifications::routeNotificationFor()`
     * already falls back to `$this->email` (a plain, always-present column
     * on this model) when no override method exists — see that trait's
     * `default => $this->email` match arm. Adding one here would be a dead,
     * behaviorally-identical override.
     */

    /**
     * The E.164 mobile number Laravel's `sms` notification channel
     * ({@see SmsChannel}) should deliver to, or
     * `null` when this customer has no mobile on file or it isn't in E.164
     * shape (e.g. an AU-local `04xxxxxxxx` number never reformatted to
     * `+614xxxxxxxx` — see `StoreOrderRequest`'s mobile-format fix,
     * Phase 7). Laravel's own dispatch already no-ops a channel whose route
     * method returns empty ({@see MailChannel::send()}'s
     * identical "no route, no attempt" check is the precedent this app's
     * own `SmsChannel` mirrors), so no extra guarding is needed at the
     * call site.
     */
    public function routeNotificationForSms(): ?string
    {
        if (blank($this->mobile)) {
            return null;
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $this->mobile) === 1 ? $this->mobile : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
        ];
    }
}
