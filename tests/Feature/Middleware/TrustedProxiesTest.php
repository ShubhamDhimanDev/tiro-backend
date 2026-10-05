<?php

use App\Models\EmailOtpChallenge;
use Illuminate\Support\Facades\Mail;

/**
 * `bootstrap/app.php`'s `$middleware->trustProxies(at: ['127.0.0.1'])` —
 * production runs Nginx and PHP-FPM on the same box, so Laravel must trust
 * that loopback hop's `X-Forwarded-For` header, or every request behind it
 * would resolve to `$request->ip() === '127.0.0.1'`, collapsing the per-IP
 * OTP-request/login throttles in AppServiceProvider/FortifyServiceProvider
 * onto a single shared bucket for every customer (see the finding this
 * follows up on).
 *
 * Exercised against the real `POST /api/v1/auth/otp/request` route (which
 * persists `$request->ip()` onto `EmailOtpChallenge.ip_address`, see
 * `EmailOtpService::issue()`) rather than a synthetic test route, so this
 * also proves the proxy trust is actually wired in `bootstrap/app.php` and
 * not just configured in isolation.
 *
 * Laravel's test client defaults the connecting address (`REMOTE_ADDR`) to
 * `127.0.0.1` unless overridden — the same loopback address configured as
 * this deployment's only trusted proxy hop, so simulating "arrives via
 * Nginx on the same box" needs no extra server variable in the first test
 * below.
 */
it('resolves the real client ip from x-forwarded-for when the request comes from the trusted loopback proxy', function () {
    Mail::fake();

    $this->withHeader('X-Forwarded-For', '203.0.113.7')
        ->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])
        ->assertOk();

    $challenge = EmailOtpChallenge::query()->latest('id')->firstOrFail();

    expect($challenge->ip_address)->toBe('203.0.113.7');
});

it('ignores a forwarded-for header when the connecting address is not the trusted proxy', function () {
    Mail::fake();

    $this->withHeaders([
        'X-Forwarded-For' => '203.0.113.7',
        'REMOTE_ADDR' => '198.51.100.9',
    ])->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])
        ->assertOk();

    $challenge = EmailOtpChallenge::query()->latest('id')->firstOrFail();

    expect($challenge->ip_address)->toBe('198.51.100.9');
});
