<?php

namespace App\Contracts;

use App\Console\Commands\ReleaseExpiredHoldsCommand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for any Eloquent model that participates in the generic
 * hold-with-TTL mechanism — see
 * docs/architecture/04-booking-capacity-engine.md's "Reservation pattern"
 * section. `Booking` is the only implementor today; a future promo-stock or
 * inventory-reservation hold implements the same two members and gets added
 * to `config('holds.models')`.
 *
 * Deliberately not a shared polymorphic hold table — each implementor keeps
 * its own native hold-state columns (`Booking`'s `status`/`hold_expires_at`
 * today) with whatever shape actually fits its domain. What's shared is the
 * locking convention (`Cache::lock()`, `{domain}-hold:{...}` key naming) and
 * this sweep contract, not a table.
 *
 * @mixin Model
 *
 * @template TModel of Model
 */
interface HasHold
{
    /**
     * Release this row's hold: set it to its "timed out, nobody confirmed
     * it" terminal state, release the cache lock (database store by default) if still held, and clear
     * `hold_expires_at` (or the model's equivalent). Called identically by
     * the delayed per-hold job and the every-minute safety-net sweep, so the
     * actual release logic lives in exactly one place.
     */
    public function releaseHold(): void;

    /**
     * Scope a query to rows whose hold has expired and are still sitting in
     * a hold state — the sweep command's `WHERE` clause. Implemented as a
     * standard Eloquent local scope (`scopeExpiredHolds`), invoked via
     * `Model::query()->expiredHolds()` by any *statically-known* caller
     * (e.g. a future super-admin-agent dispatch-board query).
     *
     * {@see ReleaseExpiredHoldsCommand}, which
     * dispatches across a config-driven list of arbitrary holdable classes,
     * deliberately does NOT call this method through the interface type —
     * the concrete model class isn't known until runtime there, and
     * Eloquent's `Builder<TModel>` generics are invariant, so a generically
     * dispatched call against an unresolved `TModel` can't be made to
     * type-check against each implementor's own concretely-typed override
     * (e.g. `Booking`'s `Builder<Booking>`) without widening this
     * interface's types into something meaningless. It instead calls the
     * same underlying scope dynamically via Eloquent's own named-scope
     * `__call` forwarding, which is exactly the mechanism this method name
     * ends up invoked through either way.
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeExpiredHolds(Builder $query): Builder;
}
