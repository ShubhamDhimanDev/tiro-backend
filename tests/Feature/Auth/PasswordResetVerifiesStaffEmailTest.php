<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;

/**
 * Covers item 1 of the Phase 0 auth security review: `User` now implements
 * `MustVerifyEmail`, and completing a password reset — the invite mechanism
 * used by Admin\UserController@store — is wired (via
 * App\Listeners\MarkStaffEmailAsVerifiedOnPasswordReset) to count as proof
 * of mailbox ownership for staff.
 */
it('marks an unverified staff user as email-verified after completing a password reset', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    $user = User::factory()->unverified()->create();
    expect($user->hasVerifiedEmail())->toBeFalse();

    $token = Password::broker()->createToken($user);

    $response = $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-new-reasonably-strong-password',
        'password_confirmation' => 'a-new-reasonably-strong-password',
    ]);

    $response->assertSessionHasNoErrors();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('leaves an already-verified user’s verified timestamp untouched on password reset', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    $user = User::factory()->create();
    $originalVerifiedAt = $user->email_verified_at;

    $token = Password::broker()->createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-new-reasonably-strong-password',
        'password_confirmation' => 'a-new-reasonably-strong-password',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->email_verified_at->equalTo($originalVerifiedAt))->toBeTrue();
});

it('actually gates a verified-only route for an unverified staff user, proving the middleware is no longer a no-op', function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());

    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('verification.notice'));
});

/**
 * Regression test for the Phase 7 auto-discovery finding (see
 * `App\Listeners\LogNotificationDelivery`'s docblock): `app/Listeners` is
 * zero-config auto-discovered by Laravel regardless of this app's explicit
 * `Event::listen()` convention, which was registering this listener twice
 * before `MarkStaffEmailAsVerifiedOnPasswordReset` implemented
 * `ShouldBeDiscovered => false`. Only one other listener
 * (`SendEmailVerificationNotification`, a framework class outside
 * `app/Listeners` and unaffected by this bug) is registered for a different
 * event, so `PasswordReset` should resolve to exactly this app's one
 * intentional registration.
 */
it('is registered for PasswordReset exactly once, not twice via auto-discovery', function () {
    $listeners = app(Dispatcher::class)->getListeners(PasswordReset::class);

    expect($listeners)->toHaveCount(1);
});
