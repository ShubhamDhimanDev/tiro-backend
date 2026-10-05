<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * Ownership check shared by every `auth:customer`-only account endpoint that
 * scopes a customer-owned model to the authenticated customer (saved
 * vehicles, saved addresses — see docs/architecture, Phase 7 readiness
 * pass). Unlike {@see AuthorizesBookingAccess}/`OrderPolicy` (which 403 a
 * mismatched owner, since a guest-token path also exists there), these
 * routes have no guest path at all — a mismatched or nonexistent id is a
 * plain `404`, not a separate `403` branch, so a customer can't distinguish
 * "not yours" from "doesn't exist" by response code.
 *
 * Each using controller resolves its own owned-row-or-404 lookup (e.g.
 * `VehicleController::ownedVehicleOrFail()`) rather than sharing a single
 * generic `findOwnedOrFail(string $modelClass, ...)` helper here: Larastan
 * cannot propagate a `@template TModel of Model` binding through a
 * `$modelClass::query()` call where `$modelClass` is a runtime
 * `class-string<TModel>` variable (confirmed — every such attempt reports
 * "should return TModel but returns Model"), so a shared generic version
 * only trades a handful of duplicated lines for either an unfixable
 * phpstan error or a suppression, which this project's phpstan instructions
 * explicitly forbid. A few lines of real duplication per model type is the
 * honest cost of that limitation, not a design preference.
 */
trait AuthorizesCustomerOwnership
{
    private function authorizedCustomer(Request $request): Customer
    {
        /** @var Customer $customer route enforces auth:customer */
        $customer = $request->user('customer');

        return $customer;
    }
}
