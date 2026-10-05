<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Row-level authorization for {@see Booking}, layered under the module-level
 * `bookings.*` spatie permissions (which answer "can this user touch the
 * Bookings module at all") — see
 * docs/architecture/07-admin-auth-permissions.md §3.1 footnote 2. Serves
 * both authenticatable models this app has:
 *
 * - `User` (staff, `web` guard) — `view()` scopes the Technician role's
 *   `bookings.view-own` permission to their own `technician_id` within a
 *   near-term date window (see docs/architecture/07-admin-auth-permissions.md
 *   §3.1 footnote 2). Staff holding `bookings.view`/`bookings.manage`
 *   (Operations/CS/Fleet/Super Admin) see every row — that broader module
 *   grant already implies full row visibility, only the Technician's
 *   narrower `view-own` needs additional per-row scoping.
 * - `Customer` (storefront, `sanctum` guard) — `manage()` is the ownership
 *   check backing guest/authenticated reschedule/cancel, per
 *   docs/architecture/02-api-contract.md's "Auth for the two mutation routes"
 *   note: `auth:customer` + this policy, OR a guest presenting
 *   `X-Booking-Manage-Token` (checked separately by the controller — a
 *   guest has no `Authenticatable` to hand this policy at all).
 *
 * One class covering two unrelated guards is unusual, but matches the
 * contract doc's own framing exactly and avoids splitting one conceptual
 * "can this identity touch this booking row" concern across two files.
 */
class BookingPolicy
{
    /**
     * Default near-term window (days from today, inclusive) a Technician's
     * `bookings.view-own` scoping opens up. Not a security boundary — a
     * super-admin-agent UI/config call per
     * docs/architecture/07-admin-auth-permissions.md §3.1 footnote 2 — so
     * it's a plain config value, not hardcoded, ops can widen/narrow it
     * without a deploy.
     */
    private const DEFAULT_TECHNICIAN_VIEW_WINDOW_DAYS = 14;

    /**
     * Staff row-level view — see the class docblock for the scoping rules.
     */
    public function view(Authenticatable $user, Booking $booking): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($user->can('bookings.manage') || $user->can('bookings.view')) {
            return true;
        }

        if (! $user->can('bookings.view-own')) {
            return false;
        }

        $technician = $user->technician;

        if ($technician === null || $booking->technician_id !== $technician->id) {
            return false;
        }

        $windowDays = (int) config('bookings.technician_view_window_days', self::DEFAULT_TECHNICIAN_VIEW_WINDOW_DAYS);

        $today = now()->startOfDay();
        $windowEnd = now()->addDays($windowDays)->endOfDay();

        return $booking->scheduled_date->between($today, $windowEnd);
    }

    /**
     * Customer ownership check backing authenticated reschedule/cancel — see
     * the class docblock. Guests are authorized separately by the
     * controller via `X-Booking-Manage-Token`, not through this method.
     */
    public function manage(Authenticatable $user, Booking $booking): bool
    {
        return $user instanceof Customer
            && $booking->customer_id !== null
            && $booking->customer_id === $user->id;
    }
}
