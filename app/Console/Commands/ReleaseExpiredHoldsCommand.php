<?php

namespace App\Console\Commands;

use App\Contracts\HasHold;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The safety-net sweep for docs/architecture/04-booking-capacity-engine.md's
 * hold-with-TTL mechanism — catches any hold whose delayed
 * `ReleaseExpiredBookingHold`-style job never fired (worker restart, queue
 * driver hiccup, a hold created while the worker was down). Registered on an
 * every-minute schedule in routes/console.php.
 *
 * Iterates `config('holds.models')` rather than being booking-specific — a
 * future holdable model (promo-stock, inventory-reservation) is a one-line
 * config addition plus implementing {@see HasHold}, not a second sweep
 * command.
 */
#[Signature('bookings:release-expired-holds')]
#[Description('Release any hold-with-TTL row (starting with pending_hold Bookings) whose TTL has passed')]
class ReleaseExpiredHoldsCommand extends Command
{
    public function handle(): int
    {
        $released = 0;

        /** @var list<class-string> $models */
        $models = config('holds.models', []);

        foreach ($models as $modelClass) {
            $instance = new $modelClass;

            if (! $instance instanceof Model || ! $instance instanceof HasHold) {
                throw new RuntimeException("Configured holdable model [{$modelClass}] must be an Eloquent model implementing ".HasHold::class.'.');
            }

            // `Builder::scopes(['expiredHolds'])` — Laravel's own public API
            // for applying a named local scope by string at runtime —
            // rather than calling `scopeExpiredHolds()` through the
            // `HasHold` interface type directly, or the equivalent
            // `->expiredHolds()` magic-call syntax (which larastan's
            // Eloquent extension statically validates against the resolved
            // model's scope set the same way either form is written,
            // failing here for the same underlying reason). `$modelClass`
            // is only known at runtime (a config-driven class-string, not a
            // fixed type), so a strictly-typed `Builder<TModel>` interface
            // call can't be made to type-check against each implementor's
            // own concretely-typed override (e.g. `Booking`'s
            // `Builder<Booking>`) without widening those types into
            // something meaningless — see {@see HasHold::scopeExpiredHolds()}'s
            // docblock. `scopes()` is the framework-provided escape hatch
            // for exactly this "call a named scope whose name is only known
            // at runtime" case, so this isn't a suppression of a real bug.
            $rows = $instance::query()->scopes(['expiredHolds'])->get();

            foreach ($rows as $row) {
                $row->releaseHold();
                $released++;
            }
        }

        $this->info("Released {$released} expired hold(s).");

        return self::SUCCESS;
    }
}
