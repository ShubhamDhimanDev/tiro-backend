<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Events\ShouldBeDiscovered;

/**
 * Invited staff users are provisioned via `Admin\UserController@store`,
 * which sends a Fortify password-reset link as the invite — there is no
 * separate email-verification link for them. Completing that reset is
 * itself proof of mailbox ownership, so treat it as email verification too;
 * otherwise `email_verified_at` never gets set for staff and the `verified`
 * middleware gates nothing (see security review, Phase 0 auth, item 1).
 *
 * Staff created through some other path in the future (e.g. self-registration,
 * if ever enabled) go through Fortify's standard email-verification flow
 * instead, unaffected by this listener.
 *
 * Implements {@see ShouldBeDiscovered} returning `false` — see
 * `App\Listeners\LogNotificationDelivery`'s docblock for the full
 * explanation (Phase 7 finding): `Illuminate\Foundation\Application::configure()`
 * calls `->withEvents()` unconditionally, enabling Laravel's zero-config
 * event auto-discovery over `app/Listeners`, which was ALSO registering
 * this listener on top of the explicit `Event::listen()` call in
 * `AppServiceProvider::configureEventListeners()` — a real double
 * registration, confirmed via `getListeners()` before this fix. Harmless in
 * practice only because `handle()`'s own `hasVerifiedEmail()` guard checks
 * the same in-memory `$event->user` object both invocations share: the
 * first invocation's `forceFill(['email_verified_at' => ...])` already
 * mutates that shared object, so the second invocation's guard trips and
 * it no-ops before calling `save()` again — verified by reading
 * `markEmailAsVerified()`'s implementation (a plain `forceFill()->save()`,
 * no event dispatch, no audit-log write, nothing else). Fixed anyway rather
 * than left relying on that incidental guard behavior.
 */
class MarkStaffEmailAsVerifiedOnPasswordReset implements ShouldBeDiscovered
{
    public static function shouldBeDiscovered(): bool
    {
        return false;
    }

    /**
     * Handle the event.
     */
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->hasVerifiedEmail()) {
            return;
        }

        $user->markEmailAsVerified();
    }
}
