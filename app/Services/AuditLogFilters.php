<?php

namespace App\Services;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Filter input for {@see AuditLogQueryService} — the reusable query layer
 * behind `GET /admin/audit-log` (super-admin-agent's controller/route/React
 * UI, per the Phase 6 task brief; this class is what its controller
 * constructs from request input and hands to the service).
 *
 * `$actorId` is `int|'system'|null`: an integer filters to that specific
 * `User`, the literal string `'system'` filters to `actor_id IS NULL` (a
 * real, populated case — `StripeWebhookController`'s
 * `orders.payment_confirmed_after_hold_expired` row writes `actor_id:
 * null`), and `null` means no actor filter at all. One nullable/union
 * property rather than two separate booleans, matching how a single
 * "Actor" dropdown naturally serializes one selected value (an integer
 * option, or an explicit "System" option) from the admin UI.
 *
 * **Security-agent fix, 2026-09-23 (Phase 6 review):** `$actorId` is
 * normalized/validated in the constructor rather than passed through
 * verbatim. Query-string input arrives as a *string* even for a numeric
 * actor id (e.g. `?actor=5`), and {@see AuditLogQueryService::query()}'s
 * actor-scoping `when()` clauses only match `is_int($actorId)` or the exact
 * literal `'system'` — any other string (a raw numeric-string actor id from
 * an uncast query param, or outright garbage input) previously matched
 * neither clause and silently fell through to *no actor filter at all*,
 * i.e. every audit-log row across every actor, the exact "invalid input
 * silently degrades to an unfiltered everyone result" failure mode this
 * class must not have — this is a visibility-scoping filter for a
 * permission-gated ({@see AuditLogQueryService} class docblock)
 * cross-admin audit trail, not a cosmetic display filter, so silently
 * widening scope on bad input is a real information-disclosure risk, not
 * just a UX bug. Fixed by normalizing digit-only strings to `int` (so they
 * correctly match the query's `is_int()` branch — this also fixes a latent
 * functional bug, not just the security one) and throwing
 * `InvalidArgumentException` for anything else that isn't `null` or the
 * literal `'system'`, so a malformed actor filter fails the request
 * (surfaces as a 500, or a 422 if the not-yet-built controller validates
 * first) rather than ever silently widening to "everyone."
 *
 * `$auditableType` is the exact fully-qualified class-name string as
 * stored in `AuditLog.auditable_type` (e.g. `App\Models\Booking`) — see
 * {@see AuditLogQueryService::AUDITABLE_TYPES} for the closed dropdown
 * list of values ever actually written.
 *
 * `$action` is a contains-match, not an exact-match — `action` is an open,
 * growing dotted-namespace convention (`bookings.cancelled`,
 * `orders.refunded`, `promotions.updated`, ...), not a closed enum.
 */
final class AuditLogFilters
{
    public readonly int|string|null $actorId;

    public function __construct(
        int|string|null $actorId = null,
        public readonly ?string $auditableType = null,
        public readonly ?string $action = null,
        public readonly ?CarbonInterface $from = null,
        public readonly ?CarbonInterface $to = null,
    ) {
        $this->actorId = $this->normalizeActorId($actorId);
    }

    public function isSystemActor(): bool
    {
        return $this->actorId === 'system';
    }

    /**
     * @throws InvalidArgumentException if `$actorId` is a non-numeric
     *                                  string other than the literal `'system'`
     */
    private function normalizeActorId(int|string|null $actorId): int|string|null
    {
        if ($actorId === null || $actorId === 'system' || is_int($actorId)) {
            return $actorId;
        }

        if (ctype_digit($actorId)) {
            return (int) $actorId;
        }

        throw new InvalidArgumentException("Invalid audit log actor filter value: '{$actorId}'. Must be an integer, the literal 'system', or null.");
    }
}
