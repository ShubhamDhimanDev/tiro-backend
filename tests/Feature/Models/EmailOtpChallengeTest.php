<?php

use App\Models\EmailOtpChallenge;

/**
 * Item 6 of the Phase 0 auth security review: OTP code hashing is keyed on
 * a dedicated `OTP_HMAC_KEY` (config/otp.php), not the shared APP_KEY.
 */
it('hashes otp codes using the dedicated otp hmac key', function () {
    config(['otp.hmac_key' => 'test-otp-key-one']);
    $hashWithKeyOne = EmailOtpChallenge::hashCode('123456');

    config(['otp.hmac_key' => 'test-otp-key-two']);
    $hashWithKeyTwo = EmailOtpChallenge::hashCode('123456');

    expect($hashWithKeyOne)->not->toBe($hashWithKeyTwo);
});

it('is unaffected by app.key changes', function () {
    config(['otp.hmac_key' => 'stable-otp-key', 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $hashBeforeAppKeyChange = EmailOtpChallenge::hashCode('123456');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $hashAfterAppKeyChange = EmailOtpChallenge::hashCode('123456');

    expect($hashBeforeAppKeyChange)->toBe($hashAfterAppKeyChange);
});

/**
 * Fail-fast guard: an unset `OTP_HMAC_KEY` must never silently degrade to
 * HMAC-ing codes with an empty key. See docs/architecture/06-open-decisions.md
 * item 14's companion fix in EmailOtpChallenge::hashCode().
 */
it('throws when otp.hmac_key is blank', function (mixed $blankKey) {
    config(['otp.hmac_key' => $blankKey]);

    EmailOtpChallenge::hashCode('123456');
})->with([null, ''])->throws(RuntimeException::class, 'OTP_HMAC_KEY is not configured');
