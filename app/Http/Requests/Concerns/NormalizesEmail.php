<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

/**
 * Normalize the `email` input once, at the edge, in `prepareForValidation()`.
 *
 * This must happen before the value is used for DB lookups or as part of a
 * rate-limiter cache key (see AppServiceProvider's OTP limiters and
 * App\Services\Auth\PasswordLoginThrottleService) — otherwise `Jane@x.com`
 * and `jane@x.com` land in separate rate-limit buckets and silently double
 * the effective request budget for one address (see
 * docs/architecture/08-customer-auth-otp.md §1).
 */
trait NormalizesEmail
{
    protected function normalizeEmail(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }
}
