<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Authenticated owner (`auth:customer` + `BookingPolicy`) OR a guest
 * presenting a matching `X-Booking-Manage-Token` — see
 * docs/architecture/02-api-contract.md's "Auth for the three routes below"
 * note. Originally `BookingController::authorizeGuestOrOwner()`, extracted
 * to a shared trait in Phase 4 since `CartController` (mode 2) and
 * `OrderController` (step 1 of order creation) both reuse the exact same
 * check verbatim — see 02-api-contract.md's "Cart, Checkout & Payment
 * endpoints" section, which calls this out by name.
 */
trait AuthorizesBookingAccess
{
    private function authorizeGuestOrOwner(Request $request, Booking $booking): void
    {
        $customer = $request->user('customer');

        if ($customer !== null) {
            abort_if(Gate::forUser($customer)->denies('manage', $booking), 403);

            return;
        }

        $token = $request->header('X-Booking-Manage-Token');

        abort_if(! is_string($token) || $token === '' || ! $booking->manageTokenMatches($token), 403);
    }
}
