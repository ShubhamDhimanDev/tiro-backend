<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Models\Order;
use App\Models\PriceGuaranteeClaim;
use App\Models\PriceRule;
use App\Models\Promotion;
use App\Models\User;
use App\Observers\ContentPageObserver;
use App\Observers\FaqObserver;
use App\Observers\PriceRuleObserver;
use App\Observers\PromotionObserver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * Reusable query/filter layer behind `GET /admin/audit-log`, gated
 * `permission:audit-log.view` (the exact permission slug seeded in
 * `RolesAndPermissionsSeeder`, for super_admin/operations/customer_support
 * only) — super-admin-agent builds the actual controller/route/React UI and
 * applies that middleware itself; this class is what its controller calls.
 * Same "one reusable service, not per-controller ad-hoc queries" posture as
 * `App\Services\Reporting\ReportingService`.
 */
class AuditLogQueryService
{
    /**
     * Every model that currently writes to `AuditLog`, for the admin UI's
     * `auditable_type` filter dropdown — kept here as the single source of
     * truth rather than left for the UI to hardcode independently.
     * `PriceGuaranteeClaim` writes via
     * `Admin\Promotions\PriceGuaranteeClaimController`'s approve/reject
     * actions; `User`/`Role` via role-sync
     * ({@see RoleAssignmentService}); the rest via the
     * observers documented on each ({@see PromotionObserver},
     * {@see PriceRuleObserver},
     * {@see ContentPageObserver}, {@see FaqObserver}) —
     * `ContentPage`/`Faq` added this phase.
     *
     * `Role::class` (Spatie's own model, not an app model) is a real,
     * distinct `auditable_type` value alongside `User::class` — verified
     * against `RoleAssignmentService::syncRolePermissions()`, which writes
     * `auditable_type = Role::class` for a role's own permission-bundle
     * changes (distinct from `syncRoles()`'s `auditable_type = User::class`
     * for a user's role assignment). The Phase 6 task brief's own
     * "auditable_type dropdown" list named only `User (role syncs)` and
     * omitted this — flagged back to project-manager/super-admin-agent
     * rather than silently only including what the brief listed.
     *
     * @var list<class-string>
     */
    public const AUDITABLE_TYPES = [
        Booking::class,
        Order::class,
        Promotion::class,
        PriceRule::class,
        PriceGuaranteeClaim::class,
        User::class,
        Role::class,
        ContentPage::class,
        Faq::class,
    ];

    /**
     * @return Builder<AuditLog>
     */
    public function query(AuditLogFilters $filters): Builder
    {
        return AuditLog::query()
            ->with('actor')
            ->when(
                $filters->isSystemActor(),
                fn (Builder $query) => $query->whereNull('audit_logs.actor_id'),
            )
            ->when(
                is_int($filters->actorId),
                fn (Builder $query) => $query->where('audit_logs.actor_id', $filters->actorId),
            )
            ->when(
                filled($filters->auditableType),
                fn (Builder $query) => $query->where('audit_logs.auditable_type', $filters->auditableType),
            )
            ->when(
                filled($filters->action),
                fn (Builder $query) => $query->where('audit_logs.action', 'like', '%'.$filters->action.'%'),
            )
            ->when(
                $filters->from !== null,
                fn (Builder $query) => $query->where('audit_logs.created_at', '>=', $filters->from),
            )
            ->when(
                $filters->to !== null,
                fn (Builder $query) => $query->where('audit_logs.created_at', '<=', $filters->to),
            )
            ->latest('audit_logs.created_at');
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(AuditLogFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage);
    }
}
