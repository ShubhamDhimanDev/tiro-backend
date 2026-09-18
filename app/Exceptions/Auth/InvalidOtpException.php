<?php

namespace App\Exceptions\Auth;

use App\Services\Auth\EmailOtpService;
use RuntimeException;

/**
 * Thrown by {@see EmailOtpService::verify()} for any OTP
 * verification failure — wrong/expired/consumed code, wrong purpose, or max
 * attempts exceeded. Callers translate this into the shared 422
 * `{message, errors: {code: [...]}}` shape (see
 * docs/architecture/08-customer-auth-otp.md §12) without needing to
 * distinguish the exact cause, since none of them are safe to disclose
 * beyond "this code is invalid or has expired".
 */
class InvalidOtpException extends RuntimeException
{
    public function __construct(string $message = 'This code is invalid or has expired.')
    {
        parent::__construct($message);
    }
}
