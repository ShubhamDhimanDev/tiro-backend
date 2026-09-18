<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

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
 */
class MarkStaffEmailAsVerifiedOnPasswordReset
{
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
