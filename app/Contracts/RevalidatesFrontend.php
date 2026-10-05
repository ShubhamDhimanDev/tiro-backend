<?php

namespace App\Contracts;

use App\Observers\FrontendRevalidationObserver;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for any Eloquent model whose changes should push an on-demand
 * Next.js ISR revalidation — see docs (project-architect's Phase 6
 * readiness pass) for the full webhook contract and
 * {@see FrontendRevalidationObserver} for the generic dispatch mechanism.
 * Same "build the mechanism once" posture as {@see HasHold}: one generic
 * observer ({@see FrontendRevalidationObserver}, hooked on
 * `created`/`updated`/`deleted`) registered per implementing model in
 * `AppServiceProvider::configureObservers()`, rather than each model/
 * controller hand-rolling its own `NotifyFrontendRevalidation::dispatch()`
 * call at every mutation call site.
 *
 * Each implementor decides its own tag naming and its own "is this event
 * actually worth a webhook" gating entirely inside {@see revalidationTags()}
 * — e.g. `TyreModel`/`TyreVariant` return `[]` for `created` (nothing was
 * ever cached under a not-yet-existing slug) and for `updated` when no
 * ISR-rendered field actually changed (price/stock changes are deliberately
 * never ISR-relevant in this project). Returning `[]` skips the queued job
 * entirely — no wasted dispatch for a no-op.
 *
 * @mixin Model
 */
interface RevalidatesFrontend
{
    /**
     * Resolve the ISR cache tags to invalidate for `$event`.
     *
     * @param  'created'|'updated'|'deleted'  $event
     * @return list<string> empty to skip revalidation entirely for this event
     */
    public function revalidationTags(string $event): array;
}
