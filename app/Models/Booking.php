<?php

namespace App\Models;

use App\Contracts\HasHold;
use App\Enums\BookingStatus;
use App\Http\Controllers\Api\V1\Bookings\BookingController;
use App\Mail\OtpCodeMail;
use App\Services\Bookings\BookingCancellationService;
use Database\Factories\BookingFactory;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * A fitting appointment — see docs/architecture/01-data-model.md's
 * "Booking / Jobs" section and
 * docs/architecture/04-booking-capacity-engine.md's "Reservation pattern"
 * section for the hold-with-TTL mechanism this model participates in via
 * {@see HasHold}.
 *
 * @implements HasHold<Booking>
 *
 * @property int $id
 * @property int|null $order_id
 * @property int|null $customer_id
 * @property int|null $vehicle_id
 * @property int $service_zone_id
 * @property int|null $address_id
 * @property Carbon $scheduled_date
 * @property string $slot_start
 * @property string $slot_end
 * @property int|null $technician_id
 * @property int|null $van_id
 * @property BookingStatus $status
 * @property int $duration_minutes
 * @property array<int, string>|null $addons
 * @property bool $is_flexible
 * @property string|null $promo_code
 * @property string|null $access_notes
 * @property Carbon|null $hold_expires_at
 * @property string|null $idempotency_key
 * @property string|null $manage_token_hash
 * @property int|null $cancellation_fee_amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'order_id', 'customer_id', 'vehicle_id', 'service_zone_id', 'address_id', 'scheduled_date',
    'slot_start', 'slot_end', 'technician_id', 'van_id', 'status', 'duration_minutes', 'addons', 'is_flexible', 'promo_code',
    'access_notes', 'hold_expires_at', 'idempotency_key', 'manage_token_hash', 'cancellation_fee_amount',
])]
#[Hidden(['manage_token_hash'])]
class Booking extends Model implements HasHold
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * A hold's TTL from creation — the one true source for this number.
     * Reused by {@see manageTokenCacheTtl()} (the transient manage-token
     * side-cache deliberately shares the hold's own natural window, see
     * docs/architecture/02-api-contract.md's "One-time secrets under
     * idempotent replay" convention, item 2) and by `BookingController`'s
     * `hold_expires_at` write, rather than each keeping its own copy of the
     * same number.
     */
    public const HOLD_TTL_MINUTES = 15;

    /**
     * Release this booking's hold: mark it `expired` and clear
     * `hold_expires_at`. A no-op for any booking that isn't currently in
     * `pending_hold` (already confirmed/cancelled/expired by the other
     * release path racing this one) — see
     * docs/architecture/04-booking-capacity-engine.md: "Both paths call the
     * same releaseHold() model method... so the actual release logic lives
     * in exactly one place." The cache lock itself (database store by default; Redis optional) is short-lived (10s,
     * scoped to the creation/reschedule/cancel critical section) and isn't
     * still held by the time a 15-minute hold expires, so there's nothing
     * to release there.
     */
    public function releaseHold(): void
    {
        if ($this->status !== BookingStatus::PendingHold) {
            return;
        }

        $this->forceFill([
            'status' => BookingStatus::Expired,
            'hold_expires_at' => null,
        ])->save();

        $this->releasePromotionHolds();
    }

    /**
     * Cascade-release this booking's own `held` {@see PromotionRedemption}
     * children — see docs/architecture/05-promotions-pricing.md's "Promo
     * stock-limit enforcement" section: `PromotionRedemption.hold_expires_at`
     * is always structurally equal to this booking's own, so it is
     * deliberately NOT registered in `config('holds.models')` for
     * independent sweeping; this is the one release path instead of two
     * that could drift out of sync on timing. Called both from
     * {@see releaseHold()} (natural TTL expiry, via the delayed job/sweep)
     * and from every explicit cancel path
     * ({@see BookingController::cancel()},
     * {@see BookingCancellationService::cancel()}) —
     * a cancelled booking must free its promo-stock allocation immediately
     * too, not just an expired one.
     */
    public function releasePromotionHolds(): void
    {
        $this->promotionRedemptions()->held()->get()->each->releaseHold();
    }

    /**
     * Scope to rows whose hold has expired and are still sitting in
     * `pending_hold` — the sweep command's `WHERE` clause, hitting the
     * `(status, hold_expires_at)` index.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function scopeExpiredHolds(Builder $query): Builder
    {
        return $query
            ->where('status', BookingStatus::PendingHold)
            ->where('hold_expires_at', '<', now());
    }

    /**
     * Hash a plaintext guest manage-token for storage/comparison. HMAC-SHA256
     * keyed on `OTP_HMAC_KEY` — deliberately reusing the same dedicated key
     * as {@see EmailOtpChallenge::hashCode()} rather than APP_KEY, and the
     * same one-way, non-reversible shape (never store the raw token).
     *
     * @throws RuntimeException if `OTP_HMAC_KEY` is missing/blank.
     */
    public static function hashManageToken(string $token): string
    {
        $key = (string) config('otp.hmac_key');

        if ($key === '') {
            throw new RuntimeException(
                'OTP_HMAC_KEY is not configured. Set a random value for this environment before issuing or verifying booking manage tokens.',
            );
        }

        return hash_hmac('sha256', $token, $key);
    }

    /**
     * Determine whether a presented plaintext manage token matches this
     * booking's stored hash. Uses `Hash::check()`-style constant-time
     * comparison via `hash_equals()` (HMAC output, not a slow password
     * hash — same reasoning as `EmailOtpChallenge::hashCode()`).
     */
    public function manageTokenMatches(string $token): bool
    {
        if ($this->manage_token_hash === null) {
            return false;
        }

        return hash_equals($this->manage_token_hash, static::hashManageToken($token));
    }

    /**
     * Cache key for the transient, encrypted manage-token, kept only long
     * enough to serve an `Idempotency-Key` replay of the creation request
     * with the exact original response body (including `manage_token`) —
     * the permanent `manage_token_hash` column is one-way and cannot
     * reproduce it. Bounded TTL, never read outside a replay of the same
     * idempotency key — but still a bearer-equivalent secret while it
     * lives, so {@see cacheManageToken()}/{@see cachedManageToken()}
     * encrypt it at rest rather than storing it plain (a plaintext value
     * here would otherwise survive in cache-store backups (database dump, or Redis RDB/AOF) past its
     * logical TTL).
     */
    public static function manageTokenCacheKey(int $bookingId): string
    {
        return "booking:{$bookingId}:manage-token-plain";
    }

    /**
     * How long the transient manage-token above survives for
     * idempotent-replay purposes — intentionally the same window as the
     * booking hold itself ({@see HOLD_TTL_MINUTES}), not a separately
     * chosen number; see docs/architecture/02-api-contract.md's "One-time
     * secrets under idempotent replay" convention (item 2) for why this
     * used to be 24 hours and was narrowed. Typed as `DateTimeInterface`
     * (what `Cache::put()`'s TTL parameter actually accepts) rather than a
     * specific Carbon flavor, since `now()` resolves to
     * `Carbon\CarbonImmutable` in this app (see `AppServiceProvider`'s
     * `Date::use(CarbonImmutable::class)`), not `Illuminate\Support\Carbon`.
     */
    public static function manageTokenCacheTtl(): DateTimeInterface
    {
        return now()->addMinutes(self::HOLD_TTL_MINUTES);
    }

    /**
     * Cache a guest booking's plaintext manage-token for the idempotent
     * -replay window described on {@see manageTokenCacheKey()}, encrypted
     * with `APP_KEY` via `Crypt::encryptString()`. Same "don't let a
     * bearer-equivalent secret sit anywhere unencrypted" posture as
     * {@see OtpCodeMail}'s `ShouldBeEncrypted` (a queued job
     * payload there, a cache value here — different mechanism, same call).
     * Encapsulated here rather than left to each controller call site, same
     * as {@see hashManageToken()} encapsulates the permanent-storage hash.
     */
    public static function cacheManageToken(int $bookingId, string $token): void
    {
        Cache::put(static::manageTokenCacheKey($bookingId), Crypt::encryptString($token), static::manageTokenCacheTtl());
    }

    /**
     * Retrieve and decrypt the manage-token cached by
     * {@see cacheManageToken()}, or null if it was never cached, has
     * expired, or fails to decrypt (e.g. `APP_KEY` rotated since it was
     * written) — decrypt failure is treated as "no cached token" rather
     * than a hard error, since the caller's only use for it is best-effort
     * replay of a prior response.
     */
    public static function cachedManageToken(int $bookingId): ?string
    {
        $value = Cache::get(static::manageTokenCacheKey($bookingId));

        if (! is_string($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Get the customer who made this booking (null for a guest booking not
     * yet linked to an account).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the order this booking's checkout produced, if it's gone through
     * checkout yet — backfilled onto this row when the order is created
     * (Phase 4), never set at hold-creation time. See
     * docs/architecture/01-data-model.md's `Order` section.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the fitting address for this booking, if checkout has captured
     * one yet — same backfill-at-order-creation timing as {@see order()}.
     *
     * @return BelongsTo<Address, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Get the vehicle this booking is for, if one was supplied.
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the service zone this booking falls within.
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    /**
     * Get the technician assigned to this booking.
     *
     * @return BelongsTo<Technician, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Get the van assigned to this booking.
     *
     * @return BelongsTo<Van, $this>
     */
    public function van(): BelongsTo
    {
        return $this->belongsTo(Van::class);
    }

    /**
     * Get the cart line items for this booking.
     *
     * @return HasMany<BookingLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(BookingLineItem::class);
    }

    /**
     * Get this booking's promo-stock holds/redemptions — see
     * {@see PromotionRedemption}'s docblock.
     *
     * @return HasMany<PromotionRedemption, $this>
     */
    public function promotionRedemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'status' => BookingStatus::class,
            'duration_minutes' => 'integer',
            'addons' => 'array',
            'is_flexible' => 'boolean',
            'hold_expires_at' => 'datetime',
            'cancellation_fee_amount' => 'integer',
        ];
    }
}
