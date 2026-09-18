<?php

namespace App\Services\Auth;

use App\Exceptions\Api\TooManyRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Password-login-only lockout family (§7 of
 * docs/architecture/08-customer-auth-otp.md) — separate from the OTP
 * request/verify limiters registered in AppServiceProvider, and only ever
 * updated from a *failed* password check, never a bare request, so it can
 * never block OTP login for the same email.
 *
 * Built on the low-level `RateLimiter`/`Cache` facades (both backed by
 * `config('cache.default')`) rather than a named `RateLimiter::for()`
 * limiter, because the escalating-doubling cooldown on repeat lockouts
 * isn't expressible as a single fixed-window `Limit`.
 */
class PasswordLoginThrottleService
{
    /** Wrong passwords for one email, within the rolling failure window, before a lockout triggers. */
    private const MAX_FAILED_ATTEMPTS = 5;

    /** Rolling window (seconds) in which failed attempts accumulate toward the cap above. */
    private const FAILURE_WINDOW_SECONDS = 3600;

    /** First lockout duration (seconds) — 15 minutes. */
    private const BASE_LOCKOUT_SECONDS = 900;

    /** Cap on doubling so a chronically-attacked email doesn't lock out for days. */
    private const MAX_ESCALATION_LEVEL = 4;

    /** How long an escalation level survives without a further lockout before decaying back to the base. */
    private const ESCALATION_DECAY_SECONDS = 86400;

    /** Credential-stuffing defense: failed attempts per IP across any target email. */
    private const IP_MAX_FAILED_ATTEMPTS = 20;

    private const IP_FAILURE_WINDOW_SECONDS = 3600;

    /**
     * @throws TooManyRequestsException if password login is currently
     *                                  locked out for this email or IP.
     */
    public function ensureNotLocked(string $email, string $ip): void
    {
        if (($seconds = $this->lockoutSecondsRemaining($email)) !== null) {
            throw new TooManyRequestsException($seconds);
        }

        if (RateLimiter::tooManyAttempts($this->ipKey($ip), self::IP_MAX_FAILED_ATTEMPTS)) {
            throw new TooManyRequestsException(RateLimiter::availableIn($this->ipKey($ip)));
        }
    }

    /**
     * Record a wrong-password attempt. May trigger (or escalate) a lockout.
     */
    public function recordFailure(string $email, string $ip): void
    {
        RateLimiter::hit($this->ipKey($ip), self::IP_FAILURE_WINDOW_SECONDS);

        $attempts = RateLimiter::hit($this->emailFailureKey($email), self::FAILURE_WINDOW_SECONDS);

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->escalateLockout($email);
        }
    }

    /**
     * Reset the per-email failed-attempt counter on a successful login.
     */
    public function recordSuccess(string $email): void
    {
        RateLimiter::clear($this->emailFailureKey($email));
    }

    private function escalateLockout(string $email): void
    {
        $level = min((int) Cache::get($this->levelKey($email), 0), self::MAX_ESCALATION_LEVEL);
        $lockoutSeconds = self::BASE_LOCKOUT_SECONDS * (2 ** $level);

        Cache::put($this->lockoutKey($email), now()->addSeconds($lockoutSeconds)->getTimestamp(), $lockoutSeconds);
        Cache::put($this->levelKey($email), $level + 1, self::ESCALATION_DECAY_SECONDS);

        RateLimiter::clear($this->emailFailureKey($email));
    }

    private function lockoutSecondsRemaining(string $email): ?int
    {
        $until = Cache::get($this->lockoutKey($email));

        if (! is_int($until)) {
            return null;
        }

        $remaining = $until - now()->getTimestamp();

        return $remaining > 0 ? $remaining : null;
    }

    private function emailFailureKey(string $email): string
    {
        return "login-fails:{$email}";
    }

    private function ipKey(string $ip): string
    {
        return "login-fails-ip:{$ip}";
    }

    private function lockoutKey(string $email): string
    {
        return "login-lockout-until:{$email}";
    }

    private function levelKey(string $email): string
    {
        return "login-lockout-level:{$email}";
    }
}
