<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * A placed order — always attached to exactly one already-resolved
 * {@see Booking} (`booking_id` required + unique, an asymmetric relationship
 * with `Booking.order_id`, which stays nullable — see
 * docs/architecture/01-data-model.md's `Order` section). Guest-continuity
 * access uses the exact same "one-time secret under idempotent replay"
 * pattern as {@see Booking::manageTokenCacheKey()} et al — see
 * docs/architecture/02-api-contract.md's "One-time secrets under idempotent
 * replay" convention.
 *
 * @property int $id
 * @property string $order_number
 * @property int|null $customer_id
 * @property int $booking_id
 * @property int $address_id
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property int $subtotal
 * @property int $discount_total
 * @property int $flexible_discount_total
 * @property int $tax_total
 * @property int $service_fee_total
 * @property int $grand_total
 * @property string $currency
 * @property array<string, mixed>|null $fitting_details
 * @property string $idempotency_key
 * @property string|null $guest_token_hash
 * @property bool $hide_from_social_proof
 * @property Carbon|null $placed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'order_number', 'customer_id', 'booking_id', 'address_id', 'status', 'payment_status',
    'subtotal', 'discount_total', 'flexible_discount_total', 'tax_total', 'service_fee_total', 'grand_total', 'currency', 'fitting_details',
    'idempotency_key', 'guest_token_hash', 'hide_from_social_proof', 'placed_at',
])]
#[Hidden(['guest_token_hash'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * Structurally the same window as `Booking::HOLD_TTL_MINUTES`, not a
     * separately chosen number — an `Order` only ever exists attached to a
     * `Booking` that is itself still inside its own 15-minute hold at
     * order-creation time, so the two windows are the same window. See
     * docs/architecture/02-api-contract.md's "One-time secrets under
     * idempotent replay" convention.
     */
    public const GUEST_TOKEN_TTL_MINUTES = Booking::HOLD_TTL_MINUTES;

    /**
     * Hash a plaintext guest order-token for storage/comparison. Same
     * HMAC-SHA256-keyed, one-way shape as {@see Booking::hashManageToken()}
     * — never store the raw token.
     *
     * @throws RuntimeException if `OTP_HMAC_KEY` is missing/blank.
     */
    public static function hashGuestToken(string $token): string
    {
        $key = (string) config('otp.hmac_key');

        if ($key === '') {
            throw new RuntimeException(
                'OTP_HMAC_KEY is not configured. Set a random value for this environment before issuing or verifying order guest tokens.',
            );
        }

        return hash_hmac('sha256', $token, $key);
    }

    /**
     * Determine whether a presented plaintext guest token matches this
     * order's stored hash. Constant-time comparison via `hash_equals()`,
     * same as {@see Booking::manageTokenMatches()}.
     */
    public function guestTokenMatches(string $token): bool
    {
        if ($this->guest_token_hash === null) {
            return false;
        }

        return hash_equals($this->guest_token_hash, static::hashGuestToken($token));
    }

    /**
     * Cache key for the transient, encrypted guest token — mirrors
     * {@see Booking::manageTokenCacheKey()} exactly, serving an
     * `Idempotency-Key` replay of the creation request with the exact
     * original response body (including `order_token`).
     */
    public static function guestTokenCacheKey(int $orderId): string
    {
        return "order:{$orderId}:guest-token-plain";
    }

    /**
     * How long the transient guest token above survives for
     * idempotent-replay purposes — see {@see GUEST_TOKEN_TTL_MINUTES}.
     */
    public static function guestTokenCacheTtl(): DateTimeInterface
    {
        return now()->addMinutes(self::GUEST_TOKEN_TTL_MINUTES);
    }

    /**
     * Cache a guest order's plaintext token for the idempotent-replay
     * window, encrypted with `APP_KEY` via `Crypt::encryptString()` — same
     * "don't let a bearer-equivalent secret sit anywhere unencrypted"
     * posture as {@see Booking::cacheManageToken()}.
     */
    public static function cacheGuestToken(int $orderId, string $token): void
    {
        Cache::put(static::guestTokenCacheKey($orderId), Crypt::encryptString($token), static::guestTokenCacheTtl());
    }

    /**
     * Retrieve and decrypt the guest token cached by
     * {@see cacheGuestToken()}, or null if it was never cached, has
     * expired, or fails to decrypt — decrypt failure is treated as "no
     * cached token" rather than a hard error, same as
     * {@see Booking::cachedManageToken()}.
     */
    public static function cachedGuestToken(int $orderId): ?string
    {
        $value = Cache::get(static::guestTokenCacheKey($orderId));

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
     * Get the customer who placed this order (null for a guest order not
     * yet linked to an account).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the booking this order was created against.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the fitting address for this order.
     *
     * @return BelongsTo<Address, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Get the commerce line items for this order.
     *
     * @return HasMany<OrderLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(OrderLineItem::class);
    }

    /**
     * Get every payment/refund transaction against this order.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'flexible_discount_total' => 'integer',
            'tax_total' => 'integer',
            'service_fee_total' => 'integer',
            'grand_total' => 'integer',
            'fitting_details' => 'array',
            'hide_from_social_proof' => 'boolean',
            'placed_at' => 'datetime',
        ];
    }
}
