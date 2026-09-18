<?php

namespace App\Exceptions\Api;

use App\Services\Auth\PasswordLoginThrottleService;
use RuntimeException;

/**
 * A rate-limit/lockout violation that isn't produced by the stock
 * `throttle:<limiter>` middleware — currently only the escalating
 * per-email password-login lockout (§7), which needs custom doubling logic
 * {@see PasswordLoginThrottleService} can't express as a
 * plain `Illuminate\Cache\RateLimiting\Limit`. Rendered by
 * `bootstrap/app.php` into the same `{message, retry_after}` 429 shape used
 * for every other rate-limit case in the customer-auth API surface.
 */
class TooManyRequestsException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfter,
        string $message = 'Too many attempts. Please try again later.',
    ) {
        parent::__construct($message);
    }
}
