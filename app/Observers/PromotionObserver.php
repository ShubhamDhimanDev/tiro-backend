<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Promotion;
use Illuminate\Support\Facades\Auth;

/**
 * Writes an {@see AuditLog} row on every {@see Promotion} create/update/
 * delete — see docs/architecture/05-promotions-pricing.md's audit-trail
 * requirement (§9). A model observer, not an explicit `AuditLog::create()`
 * call inside a controller — a deliberate deviation from this project's
 * usual "explicit call at the call site" convention (see
 * `OrderController`/`OrderRefundController`), because `Promotion`'s admin
 * CRUD screen/controller is super-admin-agent's to build, not backend-agent's
 * this phase (see the Phase 5 handback) — an observer guarantees audit
 * coverage regardless of which controller ends up writing to this model,
 * now or later, rather than depending on a not-yet-written call site to
 * remember it. Registered in `AppServiceProvider::boot()`.
 *
 * **Do not also add an explicit `AuditLog::create()` call for `Promotion`
 * mutations anywhere else** — that would double-log.
 */
class PromotionObserver
{
    public function created(Promotion $promotion): void
    {
        $this->log($promotion, 'promotions.created', before: null, after: $promotion->getAttributes());
    }

    public function updated(Promotion $promotion): void
    {
        $changes = $promotion->getChanges();

        if ($changes === []) {
            return;
        }

        $before = array_intersect_key($promotion->getOriginal(), $changes);

        $this->log($promotion, 'promotions.updated', $before, $changes);
    }

    public function deleted(Promotion $promotion): void
    {
        $this->log($promotion, 'promotions.deleted', before: $promotion->getAttributes(), after: null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Promotion $promotion, string $action, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'auditable_type' => Promotion::class,
            'auditable_id' => $promotion->id,
            'action' => $action,
            'actor_id' => Auth::id(),
            'before' => $before,
            'after' => $after,
        ]);
    }
}
