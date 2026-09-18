<?php

namespace App\Models;

use Database\Factories\EmailOtpChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property string $email
 * @property string $code_hash
 * @property string $purpose
 * @property string|null $pending_password_hash
 * @property Carbon $expires_at
 * @property int $attempts
 * @property Carbon|null $consumed_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
#[Fillable(['email', 'code_hash', 'purpose', 'pending_password_hash', 'expires_at', 'attempts', 'consumed_at', 'ip_address', 'user_agent'])]
#[Hidden(['code_hash', 'pending_password_hash'])]
class EmailOtpChallenge extends Model
{
    /** @use HasFactory<EmailOtpChallengeFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * This table only tracks `created_at` (set at the database level via
     * `useCurrent()`) — there is no `updated_at` column.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Hash a plaintext OTP code for storage/comparison.
     *
     * HMAC-SHA256 keyed on a dedicated `OTP_HMAC_KEY` (config/otp.php) —
     * deliberately not APP_KEY, which is shared with sessions/cookies/signed
     * URLs and would give this single-purpose hash unnecessary blast radius.
     * Also deliberately not a slow password hash (bcrypt/argon): a 6-digit
     * code's brute-force resistance comes from the attempt cap + TTL, not
     * hash cost, so a slow hash buys nothing here (see
     * docs/architecture/08-customer-auth-otp.md §5/§6).
     *
     * Fails fast if `OTP_HMAC_KEY` is unset: `config('otp.hmac_key')` would
     * otherwise be `null`, silently cast to an empty string, and every code
     * would be HMAC'd with an empty key — a weak, silent misconfiguration
     * rather than a loud one. This must never be reachable on a real
     * request; a missing key means the environment is broken, not that OTPs
     * should keep working with a degraded hash.
     *
     * @throws RuntimeException if `OTP_HMAC_KEY` is missing/blank.
     */
    public static function hashCode(string $code): string
    {
        $key = (string) config('otp.hmac_key');

        if ($key === '') {
            throw new RuntimeException(
                'OTP_HMAC_KEY is not configured. Set a random value for this environment before issuing or verifying OTP codes.',
            );
        }

        return hash_hmac('sha256', $code, $key);
    }
}
