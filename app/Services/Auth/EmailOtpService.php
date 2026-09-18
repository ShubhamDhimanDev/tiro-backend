<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidOtpException;
use App\Mail\OtpCodeMail;
use App\Models\EmailOtpChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Shared OTP challenge mechanics for all three purposes (registration,
 * login, password_reset) — see docs/architecture/08-customer-auth-otp.md §5.
 */
class EmailOtpService
{
    private const CODE_LENGTH = 6;

    private const TTL_MINUTES = 10;

    private const MAX_VERIFY_ATTEMPTS = 5;

    /**
     * Create a new OTP challenge and queue its delivery email.
     *
     * Does not touch any `Customer` row — callers decide what, if anything,
     * to do with the customer for the given purpose.
     */
    public function issue(
        string $email,
        string $purpose,
        Request $request,
        ?string $pendingPasswordHash = null,
    ): EmailOtpChallenge {
        $code = $this->generateCode();

        $challenge = EmailOtpChallenge::create([
            'email' => $email,
            'code_hash' => EmailOtpChallenge::hashCode($code),
            'purpose' => $purpose,
            'pending_password_hash' => $pendingPasswordHash,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'attempts' => 0,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        Mail::to($email)->queue(new OtpCodeMail($code, $purpose));

        return $challenge;
    }

    /**
     * Validate a submitted code against the latest unconsumed challenge for
     * the given email/purpose. Returns the challenge (still unconsumed —
     * callers must call `consume()` once they've applied its side effects)
     * on success.
     *
     * @throws InvalidOtpException wrong/expired/consumed code, wrong
     *                             purpose, or max attempts exceeded — all
     *                             indistinguishable to the caller by design.
     */
    public function verify(string $email, string $code, string $purpose): EmailOtpChallenge
    {
        $challenge = EmailOtpChallenge::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $challenge instanceof EmailOtpChallenge) {
            throw new InvalidOtpException;
        }

        if ($challenge->attempts >= self::MAX_VERIFY_ATTEMPTS) {
            throw new InvalidOtpException;
        }

        if ($challenge->expires_at->isPast()) {
            throw new InvalidOtpException;
        }

        if (! hash_equals($challenge->code_hash, EmailOtpChallenge::hashCode($code))) {
            $challenge->increment('attempts');

            throw new InvalidOtpException;
        }

        return $challenge;
    }

    public function consume(EmailOtpChallenge $challenge): void
    {
        $challenge->forceFill(['consumed_at' => now()])->save();
    }

    private function generateCode(): string
    {
        return (string) random_int(
            10 ** (self::CODE_LENGTH - 1),
            (10 ** self::CODE_LENGTH) - 1,
        );
    }
}
