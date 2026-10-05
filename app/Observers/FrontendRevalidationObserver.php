<?php

namespace App\Observers;

use App\Contracts\RevalidatesFrontend;
use App\Jobs\NotifyFrontendRevalidation;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic `created`/`updated`/`deleted` hook for any model implementing
 * {@see RevalidatesFrontend} — same "build the mechanism once" posture as
 * `App\Contracts\HasHold`. Registered once per implementing model in
 * `AppServiceProvider::configureObservers()` (mirroring
 * `PromotionObserver`'s existing registration pattern, and its exact
 * created/updated/deleted method shape), so no controller/service call
 * site has to remember to dispatch the webhook itself.
 *
 * Deliberately hooks `created`/`updated` rather than the single generic
 * `saved` event: Eloquent's `Model::save()` only fires `updated` (and only
 * syncs `getChanges()`) when `performUpdate()` actually ran an `UPDATE`
 * query, which only happens `if (count($this->getDirtyForUpdate()) > 0)` —
 * see `Illuminate\Database\Eloquent\Model::performUpdate()`. `saved` fires
 * unconditionally instead (even for a true no-op `->save()` call with
 * nothing dirty), and worse, `$model->wasRecentlyCreated` is never reset by
 * a later `->update()` call on the same in-memory instance — so a generic
 * `saved` hook trying to distinguish "created" from "updated" via that flag
 * misclassifies any create-then-update-same-instance sequence (a real
 * pattern, not just a test artifact) as a second `created` event. Hooking
 * `updated` directly sidesteps both problems for free and mirrors
 * `PromotionObserver`'s own method shape exactly.
 */
class FrontendRevalidationObserver
{
    public function created(Model $model): void
    {
        $this->dispatch($model, 'created');
    }

    /**
     * Only ever invoked by Eloquent when a real dirty write happened (see
     * class docblock), so `$model->getChanges()` here always reflects an
     * actual change set — this project's exhaustive-enum convention still
     * mirrors `PromotionObserver::updated()`'s belt-and-braces
     * `getChanges() === []` guard on top of that, in case a future Eloquent
     * version or an unusual save path changes that guarantee.
     */
    public function updated(Model $model): void
    {
        if ($model->getChanges() === []) {
            return;
        }

        $this->dispatch($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->dispatch($model, 'deleted');
    }

    /**
     * @param  'created'|'updated'|'deleted'  $event
     */
    private function dispatch(Model $model, string $event): void
    {
        if (! $model instanceof RevalidatesFrontend) {
            return;
        }

        $tags = $model->revalidationTags($event);

        if ($tags === []) {
            return;
        }

        NotifyFrontendRevalidation::dispatch($tags);
    }
}
