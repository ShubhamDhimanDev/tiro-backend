<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\AdminGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory, no-exceptions 2FA gate for every one of the six staff roles.
 *
 * Runs once, at first login: an authenticated `User` who has neither
 * confirmed TOTP nor registered a passkey is redirected to Fortify's
 * existing 2FA/passkey setup UI (`security.edit`) before any other
 * authenticated admin route is reachable. Not a per-request challenge —
 * once satisfied, this middleware is a no-op for that user forever.
 */
class EnsureTwoFactorEnabled
{
    /**
     * The only route a user who hasn't satisfied 2FA yet is allowed to
     * reach — the settings page where they enroll. Everything else Fortify
     * registers for enrolling (2FA enable/confirm, passkey registration) is
     * routed directly by the `laravel/fortify` and `laravel/passkeys`
     * packages outside these custom route groups, so it is unaffected by
     * this middleware and never needs to be exempted here.
     */
    private const SETUP_ROUTE = 'security.edit';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = AdminGuard::optionalUser($request);

        if ($user === null || $this->hasSatisfiedTwoFactor($user) || $request->routeIs(self::SETUP_ROUTE)) {
            return $next($request);
        }

        return redirect()->route(self::SETUP_ROUTE);
    }

    /**
     * A confirmed TOTP secret or a registered passkey both satisfy the 2FA
     * requirement — a passkey is treated as equivalent to, not additional
     * to, TOTP (see 07-admin-auth-permissions.md §4).
     */
    private function hasSatisfiedTwoFactor(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null || $user->passkeys()->exists();
    }
}
