<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\SizePriceBand;
use App\Models\SizePriceTier;
use App\Services\Commerce\SizePricingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Audit-logs every {@see SizePriceBand}/{@see SizePriceTier} change (a pricing
 * lever, like `PriceRule`) and flushes the cached band list so the next
 * price calculation sees it. Registered for both models in
 * `AppServiceProvider::configureObservers()`; the controller must not also
 * write an `AuditLog` row.
 */
class SizePriceObserver
{
    public function created(Model $model): void
    {
        $this->log($model, 'created', before: null, after: $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();

        if ($changes === []) {
            return;
        }

        $this->log($model, 'updated', array_intersect_key($model->getOriginal(), $changes), $changes);
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', before: $model->getAttributes(), after: null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Model $model, string $event, ?array $before, ?array $after): void
    {
        SizePricingService::flush();

        $prefix = $model instanceof SizePriceTier ? 'size_price_tiers' : 'size_price_bands';

        AuditLog::create([
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => "{$prefix}.{$event}",
            'actor_id' => Auth::id(),
            'before' => $before,
            'after' => $after,
        ]);
    }
}
