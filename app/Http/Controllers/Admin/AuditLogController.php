<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogFilters;
use App\Services\AuditLogQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /admin/audit-log` — gated on the standalone `audit-log.view`
 * permission (verified against `RolesAndPermissionsSeeder`'s
 * `STANDALONE_PERMISSIONS_BY_ROLE`: super_admin, operations, and
 * customer_support only — deliberately not ecommerce/fleet/technician, see
 * that const's docblock on why `AuditLog` rows carry PII/role-change history
 * `reporting.view` holders like Fleet have no business seeing).
 *
 * Builds an {@see AuditLogFilters} value object from request input and hands
 * it to {@see AuditLogQueryService} (backend-agent's reusable query layer) —
 * no query logic of its own beyond that construction.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly AuditLogQueryService $auditLog) {}

    public function index(Request $request): Response
    {
        $actorInput = $request->string('actor_id')->trim()->toString();
        $auditableType = $request->string('auditable_type')->trim()->toString() ?: null;
        $action = $request->string('action')->trim()->toString() ?: null;
        $from = $this->parseDate($request->string('from')->toString());
        $to = $this->parseDate($request->string('to')->toString())?->endOfDay();

        $filters = new AuditLogFilters(
            actorId: $this->resolveActorId($actorInput),
            auditableType: $auditableType,
            action: $action,
            from: $from,
            to: $to,
        );

        $logs = $this->auditLog->paginate($filters, self::PER_PAGE)->withQueryString();

        return Inertia::render('audit-log/index', [
            'logs' => $logs,
            'filters' => [
                'actor_id' => $actorInput ?: null,
                'auditable_type' => $auditableType,
                'action' => $action,
                'from' => $request->string('from')->toString() ?: null,
                'to' => $request->string('to')->toString() ?: null,
            ],
            'actors' => User::query()->orderBy('name')->get(['id', 'name']),
            'auditableTypes' => AuditLogQueryService::AUDITABLE_TYPES,
        ]);
    }

    /**
     * `'system'` maps to the literal string (matched by
     * `AuditLogFilters::isSystemActor()`), a numeric string maps to that
     * `User` id, anything else (including empty) means no actor filter.
     */
    private function resolveActorId(string $value): int|string|null
    {
        if ($value === '') {
            return null;
        }

        if ($value === 'system') {
            return 'system';
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if ($value === '' || ! strtotime($value)) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
