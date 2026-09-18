<?php

use App\Mail\OtpCodeMail;
use App\Models\Customer;
use App\Models\EmailOtpChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function queuedOtpCode(): string
{
    $code = null;

    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

it('registers a new customer by emailing an otp, without activating the account yet', function () {
    Mail::fake();

    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'Jane@Example.com',
        'password' => 'a-reasonably-strong-password',
    ]);

    $response->assertOk();
    expect($response->json())->toHaveKey('message')->not->toHaveKey('data');

    $customer = Customer::query()->where('email', 'jane@example.com')->first();

    expect($customer)->not->toBeNull();
    expect($customer->email_verified_at)->toBeNull();
    expect($customer->password)->toBeNull();

    Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->purpose === 'registration');
});

it('activates a customer and issues a session token after verifying the registration code', function () {
    Mail::fake();

    $email = 'jane@example.com';

    $this->postJson('/api/v1/auth/register', [
        'email' => $email,
        'password' => 'a-reasonably-strong-password',
    ])->assertOk();

    $response = $this->postJson('/api/v1/auth/register/verify', [
        'email' => $email,
        'code' => queuedOtpCode(),
    ]);

    $response->assertOk()->assertJsonStructure([
        'data' => ['token', 'token_type', 'expires_at', 'customer' => ['id', 'name', 'email', 'mobile']],
    ]);
    expect($response->json('data.token_type'))->toBe('Bearer');

    $customer = Customer::query()->where('email', $email)->first();
    expect($customer->email_verified_at)->not->toBeNull();
    expect($customer->password)->not->toBeNull();
});

it('rejects registration for an already activated email', function () {
    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'jane@example.com',
        'password' => 'a-reasonably-strong-password',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('email');
    expect($response->json('errors.email.0'))
        ->toBe('An account already exists for this email — log in instead.');
});

it('reuses an existing guest-only customer row on registration instead of creating a duplicate', function () {
    Mail::fake();

    $guest = Customer::factory()->create(['email' => 'guest@example.com', 'mobile' => '0411222333']);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'guest@example.com',
        'password' => 'a-reasonably-strong-password',
    ])->assertOk();

    expect(Customer::query()->where('email', 'guest@example.com')->count())->toBe(1);

    $reused = Customer::query()->where('email', 'guest@example.com')->sole();
    expect($reused->id)->toBe($guest->id)
        ->and($reused->mobile)->toBe('0411222333')
        ->and($reused->password)->toBeNull();
});

it('rejects weak passwords on registration', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'jane@example.com',
        'password' => 'short',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('password');
});

it('returns a 422 with a code error for a wrong otp code', function () {
    Mail::fake();

    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertOk();

    $realCode = queuedOtpCode();
    $wrongCode = $realCode === '000000' ? '111111' : '000000';

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'email' => 'jane@example.com',
        'code' => $wrongCode,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('code');
});

it('logs in an activated customer via otp without ever activating a new account', function () {
    Mail::fake();

    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertOk();

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'email' => 'jane@example.com',
        'code' => queuedOtpCode(),
    ]);

    $response->assertOk()->assertJsonStructure([
        'data' => ['token', 'token_type', 'expires_at', 'customer'],
    ]);
});

it('returns 404 when an otp code is valid but no activated account exists for the email', function () {
    Mail::fake();

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'ghost@example.com'])->assertOk();

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'email' => 'ghost@example.com',
        'code' => queuedOtpCode(),
    ]);

    $response->assertStatus(404);
    expect($response->json('message'))->toBe('No account found for this email — register to continue.');
});

it('404s on otp/verify for a guest-only customer email that has not completed registration, without activating it', function () {
    Mail::fake();

    $guest = Customer::factory()->create(['email' => 'guest@example.com']);

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'guest@example.com'])->assertOk();

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'email' => 'guest@example.com',
        'code' => queuedOtpCode(),
    ]);

    $response->assertStatus(404);
    expect($response->json('message'))->toBe('No account found for this email — register to continue.');

    expect($guest->fresh()->email_verified_at)->toBeNull()
        ->and($guest->fresh()->password)->toBeNull();
});

it('invalidates the challenge after 5 wrong otp verify attempts, rejecting even the correct code afterward', function () {
    Mail::fake();

    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertOk();
    $correctCode = queuedOtpCode();
    $wrongCode = $correctCode === '000000' ? '111111' : '000000';

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'jane@example.com',
            'code' => $wrongCode,
        ])->assertStatus(422);
    }

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'email' => 'jane@example.com',
        'code' => $correctCode,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('code');
});

it('expires an otp code after its 10 minute ttl', function () {
    Mail::fake();

    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertOk();
    $code = queuedOtpCode();

    $this->travel(11)->minutes();

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'email' => 'jane@example.com',
        'code' => $code,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('code');
});

it('enforces the 5 per hour and 10 per day otp request caps per email', function () {
    Mail::fake();

    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    // Two full hourly windows, 5 requests apiece (10 total for the day),
    // resetting the hourly window between them via time travel so that
    // afterwards only the daily cap is left standing.
    for ($window = 0; $window < 2; $window++) {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertOk();
            $this->travel(61)->seconds();
        }

        // The 6th request inside this same hourly window hits the 5/hour cap.
        $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertStatus(429);

        $this->travel(61)->minutes(); // reset the hourly window, stay inside the same day
    }

    // A fresh hourly window (0 requests sent in it so far) but the 10th
    // request for the day has already gone out — this one is blocked
    // purely by the 10/day cap, not the hourly one.
    $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertStatus(429);
});

it('enforces the 20 per hour per-ip otp request cap across different emails', function () {
    Mail::fake();

    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/api/v1/auth/otp/request', ['email' => "customer{$i}@example.com"])->assertOk();
    }

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'customer20@example.com'])->assertStatus(429);
});

it('enforces the 10 per hour per email+ip otp verify cap', function () {
    Mail::fake();

    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertOk();
    $realCode = queuedOtpCode();
    $wrongCode = $realCode === '000000' ? '111111' : '000000';

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'jane@example.com',
            'code' => $wrongCode,
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/otp/verify', [
        'email' => 'jane@example.com',
        'code' => $wrongCode,
    ])->assertStatus(429);
});

it('logs in an activated customer with the correct password', function () {
    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()->assertJsonStructure([
        'data' => ['token', 'token_type', 'expires_at', 'customer'],
    ]);
});

it('returns the identical generic 401 for a wrong password, an unknown email, and an unverified (guest-only) account', function () {
    Customer::factory()->activated()->create(['email' => 'jane@example.com']);
    Customer::factory()->create(['email' => 'guest@example.com']); // guest row: no password, unverified

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => 'wrong-password',
    ]);

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever-password',
    ]);

    $unverifiedAccount = $this->postJson('/api/v1/auth/login', [
        'email' => 'guest@example.com',
        'password' => 'whatever-password',
    ]);

    $wrongPassword->assertStatus(401);
    $unknownEmail->assertStatus(401);
    $unverifiedAccount->assertStatus(401);
    expect($wrongPassword->json())->not->toHaveKey('errors');
    expect($wrongPassword->json('message'))
        ->toBe($unknownEmail->json('message'))
        ->toBe($unverifiedAccount->json('message'));
});

it('always runs a hash check on login, even for an unregistered email, to avoid a timing signal', function () {
    Hash::shouldReceive('check')->once()->andReturn(false);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever-password',
    ]);

    $response->assertStatus(401);
});

it('resets a password, revokes existing tokens, and issues a fresh one', function () {
    Mail::fake();

    $customer = Customer::factory()->activated()->create(['email' => 'jane@example.com']);
    $oldToken = $customer->createToken('storefront')->plainTextToken;

    expect($customer->tokens()->count())->toBe(1);

    $this->postJson('/api/v1/auth/password/reset/request', ['email' => 'jane@example.com'])->assertOk();

    $response = $this->postJson('/api/v1/auth/password/reset/verify', [
        'email' => 'jane@example.com',
        'code' => queuedOtpCode(),
        'new_password' => 'a-new-reasonably-strong-password',
    ]);

    $response->assertOk()->assertJsonStructure([
        'data' => ['token', 'token_type', 'expires_at', 'customer'],
    ]);

    // Old token(s) revoked, exactly one fresh token issued.
    expect($customer->tokens()->count())->toBe(1);

    // The revoked token must genuinely stop authenticating, not merely be
    // outnumbered by the newly issued one.
    $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->deleteJson('/api/v1/auth/session')
        ->assertUnauthorized();
});

it('creates a password-reset challenge and queues the mail for an unregistered email too, to avoid a timing signal', function () {
    Mail::fake();

    $this->postJson('/api/v1/auth/password/reset/request', ['email' => 'ghost@example.com'])->assertOk();

    expect(
        EmailOtpChallenge::query()
            ->where('email', 'ghost@example.com')
            ->where('purpose', 'password_reset')
            ->exists()
    )->toBeTrue();

    Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->purpose === 'password_reset');
});

it('creates a password-reset challenge and queues the mail for an unactivated (guest-checkout) email too', function () {
    Mail::fake();

    Customer::factory()->create(['email' => 'unactivated@example.com']); // no password, no email_verified_at

    $this->postJson('/api/v1/auth/password/reset/request', ['email' => 'unactivated@example.com'])->assertOk();

    expect(
        EmailOtpChallenge::query()
            ->where('email', 'unactivated@example.com')
            ->where('purpose', 'password_reset')
            ->exists()
    )->toBeTrue();

    Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->purpose === 'password_reset');
});

it('keys a weak new_password failure under the password error key, not new_password', function () {
    Mail::fake();

    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/password/reset/request', ['email' => 'jane@example.com'])->assertOk();

    $response = $this->postJson('/api/v1/auth/password/reset/verify', [
        'email' => 'jane@example.com',
        'code' => queuedOtpCode(),
        'new_password' => 'short',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('password');
    expect($response->json('errors'))->not->toHaveKey('new_password');
});

it('revokes only the current token on session logout', function () {
    $customer = Customer::factory()->activated()->create();
    $tokenA = $customer->createToken('storefront')->plainTextToken;
    $customer->createToken('storefront');

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->deleteJson('/api/v1/auth/session');

    $response->assertNoContent();
    expect($customer->tokens()->count())->toBe(1);
});

it('revokes all tokens on logout everywhere', function () {
    $customer = Customer::factory()->activated()->create();
    $tokenA = $customer->createToken('storefront')->plainTextToken;
    $customer->createToken('storefront');

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->deleteJson('/api/v1/auth/sessions');

    $response->assertNoContent();
    expect($customer->tokens()->count())->toBe(0);
});

it('enforces the 60 second otp resend cooldown with a {message, retry_after} 429', function () {
    Mail::fake();

    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertOk();

    $response = $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com']);

    $response->assertStatus(429);
    expect($response->json())->toHaveKeys(['message', 'retry_after']);
    expect($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0);
});

it('locks out password login for an email after 5 wrong attempts, without blocking otp login', function () {
    Mail::fake();

    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    $lockedOut = $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => 'password',
    ]);

    $lockedOut->assertStatus(429);
    expect($lockedOut->json())->toHaveKeys(['message', 'retry_after']);

    // OTP login must remain fully available throughout a password lockout.
    $this->postJson('/api/v1/auth/otp/request', ['email' => 'jane@example.com'])->assertOk();

    $otpLogin = $this->postJson('/api/v1/auth/otp/verify', [
        'email' => 'jane@example.com',
        'code' => queuedOtpCode(),
    ]);

    $otpLogin->assertOk();
});

it('escalates the password lockout duration on repeated lockouts for the same email, doubling and capping at level 4 (900 * 2^4 seconds)', function () {
    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $lockOutOnceAndGetRetryAfter = function (): int {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'jane@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $locked = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
        ]);

        $locked->assertStatus(429);

        return $locked->json('retry_after');
    };

    // 900 * 2^0, 900 * 2^1, ... 900 * 2^4, then repeats at level 4 (the cap)
    // instead of continuing to double.
    $expectedDurations = [900, 1800, 3600, 7200, 14400, 14400];

    foreach ($expectedDurations as $expectedSeconds) {
        $retryAfter = $lockOutOnceAndGetRetryAfter();

        expect($retryAfter)->toBeGreaterThan($expectedSeconds - 3)->toBeLessThanOrEqual($expectedSeconds);

        $this->travel($retryAfter + 1)->seconds();
    }
});
