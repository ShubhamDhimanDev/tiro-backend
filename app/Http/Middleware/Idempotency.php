<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin, format-validation-only middleware for `Idempotency-Key`-protected
 * routes — see docs/architecture/02-api-contract.md's "Idempotency" section.
 *
 * This middleware does NOT store or replay responses itself; it only
 * enforces that the header is present and a well-formed UUID (`422`
 * otherwise). The actual dedup — looking up an existing row by
 * `idempotency_key` inside the same DB transaction as creation, and
 * returning it with its original status code instead of re-running creation
 * logic — is the controller's job (`Booking`/`Order` today; `RefundService`
 * for the admin refund route).
 *
 * Reused verbatim across both the Sanctum-token JSON API routes
 * (`POST /api/v1/bookings`/`POST /api/v1/orders`) and the session-auth
 * Inertia admin route (`POST /admin/orders/{order}/refund`). Those two
 * families need different failure shapes on a missing/malformed header, so
 * this branches on the `X-Inertia` request header (sent on every request
 * Inertia's client makes) rather than needing a second middleware class:
 * the JSON API routes keep the raw `422` body below unchanged; the Inertia
 * route gets a `back()->withErrors()` redirect so the failure degrades
 * exactly like every other admin form's validation failure does — Inertia's
 * client only threads `errors` into page props for responses carrying
 * `X-Inertia: true`, so a raw JSON 422 without that header would otherwise
 * surface as Inertia's generic non-Inertia-response error dump instead of
 * an inline field error.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (is_string($key) && $key !== '' && Str::isUuid($key)) {
            return $next($request);
        }

        $message = __('The Idempotency-Key header is required and must be a valid UUID.');

        if ($request->header('X-Inertia')) {
            return back()->withErrors(['idempotency_key' => $message]);
        }

        return response()->json([
            'message' => $message,
            'errors' => [
                'idempotency_key' => [$message],
            ],
        ], 422);
    }
}
