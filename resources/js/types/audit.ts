/**
 * Mirrors `App\Services\AuditLogQueryService::AUDITABLE_TYPES` — the closed
 * dropdown list of FQCN strings ever actually written to
 * `AuditLog.auditable_type`. Kept in sync with that PHP const, not
 * independently maintained — the controller passes the live list as a prop
 * rather than this file hardcoding it, so this type only documents the
 * shape.
 */
export type AuditableType = string;

export type AuditActor = {
    id: number;
    name: string;
};

export type AuditLogEntry = {
    id: number;
    auditable_type: string;
    auditable_id: number;
    action: string;
    actor_id: number | null;
    actor?: AuditActor | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    created_at: string;
};

/** `actor_id` is a raw query-string value here: `'system'`, a numeric-string user id, or `null` for no filter — mirrors `App\Services\AuditLogFilters`'s `int|string|null` union. */
export type AuditLogFilters = {
    actor_id: string | null;
    auditable_type: string | null;
    action: string | null;
    from: string | null;
    to: string | null;
};
