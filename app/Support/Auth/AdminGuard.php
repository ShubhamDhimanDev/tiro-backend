<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Narrows the `Customer|User|null` type Larastan infers for
 * `Request::user()` down to `User`, for the `web`-guarded admin/Settings
 * surface only — see docs/architecture/09-phpstan-guard-convention.md.
 *
 * Never use this against `/api/v1/*` (customer/sanctum-guarded) code —
 * those call sites are expected to resolve `Customer`, not `User`.
 */
final class AdminGuard
{
    /**
     * For call sites guaranteed to run behind `auth` (web-guard) middleware
     * — Settings controllers, `EnsureTwoFactorEnabled`, etc. Throws if
     * unauthenticated or resolved as anything other than `User`; both are a
     * genuine bug at these call sites, not a state to degrade from.
     */
    public static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Expected an authenticated admin (User) but none was resolved.');
        }

        return $user;
    }

    /**
     * For call sites where anonymous access is legitimate — FormRequest
     * `authorize()` checks and `abort_unless(...)` guards that should
     * degrade to "deny" rather than error, and `HandleInertiaRequests`
     * (runs on pre-login pages too). Returns null if unauthenticated;
     * still throws if a non-null, non-`User` principal is resolved — a
     * `Customer` reaching a web-guarded code path is a guard
     * misconfiguration, not something to tolerate silently.
     */
    public static function optionalUser(Request $request): ?User
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (! $user instanceof User) {
            throw new AuthenticationException('Expected an authenticated admin (User) but resolved a different principal.');
        }

        return $user;
    }
}
