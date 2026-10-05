<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\PriceRule;
use Illuminate\Support\Facades\Auth;

/**
 * Writes an {@see AuditLog} row on every {@see PriceRule} create/update/
 * delete — see docs/architecture/05-promotions-pricing.md's audit-trail
 * requirement (§9). Same observer-based approach as
 * {@see PromotionObserver} (see that class's docblock for
 * why an observer rather than an explicit controller-side call). Registered
 * in `AppServiceProvider::boot()`.
 *
 * **`PriceRuleController` deliberately does not also call
 * `AuditLog::create()`** — that would double-log.
 */
class PriceRuleObserver
{
    public function created(PriceRule $priceRule): void
    {
        $this->log($priceRule, 'price_rules.created', before: null, after: $priceRule->getAttributes());
    }

    public function updated(PriceRule $priceRule): void
    {
        $changes = $priceRule->getChanges();

        if ($changes === []) {
            return;
        }

        $before = array_intersect_key($priceRule->getOriginal(), $changes);

        $this->log($priceRule, 'price_rules.updated', $before, $changes);
    }

    public function deleted(PriceRule $priceRule): void
    {
        $this->log($priceRule, 'price_rules.deleted', before: $priceRule->getAttributes(), after: null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(PriceRule $priceRule, string $action, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'auditable_type' => PriceRule::class,
            'auditable_id' => $priceRule->id,
            'action' => $action,
            'actor_id' => Auth::id(),
            'before' => $before,
            'after' => $after,
        ]);
    }
}
