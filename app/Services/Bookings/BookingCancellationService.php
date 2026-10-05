<?php

namespace App\Services\Bookings;

use App\Enums\BookingStatus;
use App\Http\Controllers\Admin\Orders\OrderController;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff-initiated booking cancellation — the exact write extracted verbatim
 * from `DispatchBoardController::cancel()` (Phase 3) so any other admin
 * surface that needs to release a booking's technician/van slot goes
 * through the same mechanism instead of a second, potentially-diverging
 * copy of it. The Orders admin's order-cancel action
 * ({@see OrderController::cancel()}) is
 * the first such caller: cancelling an `Order` must also free its linked
 * `Booking`'s slot, not leave a cancelled order pointing at a booking still
 * occupying a van's schedule — see docs/architecture/01-data-model.md's
 * `Order.booking_id` note ("required and unique... an Order is never
 * created except from an already-resolved booking").
 *
 * Deliberately does **not** evaluate `CancellationPolicy` — see
 * docs/architecture/01-data-model.md's "Dispatch-board (staff-initiated)
 * actions never evaluate this policy" note. A staff-triggered cancellation
 * (whether from the dispatch board directly, or as a side effect of an
 * admin cancelling the order it's attached to) is a structurally different
 * business event from a customer-initiated cancel/reschedule, which goes
 * through `App\Http\Controllers\Api\V1\Bookings\BookingController::cancel()`'s
 * separate, fee-evaluating path instead — not a shared function gated by an
 * "is this staff or customer" flag that could be wrong.
 */
class BookingCancellationService
{
    /**
     * Statuses a booking may still be cancelled from via this path — mirrors
     * `DispatchBoardController::MOVABLE_STATUSES` /
     * `BookingController::RESCHEDULABLE_STATUSES` exactly (all three lists
     * describe the same "still live, not yet in a terminal state" window;
     * not consolidated into one shared constant across those two
     * pre-existing, differently-owned controllers in this pass, but any
     * *new* caller — like `OrderController` — should reference this one
     * rather than redeclaring its own copy).
     *
     * @var list<BookingStatus>
     */
    public const CANCELLABLE_STATUSES = [BookingStatus::PendingHold, BookingStatus::Confirmed];

    /**
     * Cancel `$booking`: set `status = cancelled`, clear `hold_expires_at`,
     * and write one `AuditLog` row (`action = 'bookings.cancelled'`,
     * `auditable_type = Booking::class`) so Phase 6 reporting can
     * distinguish this staff-initiated change from a customer's own
     * reschedule/cancel — see docs/architecture/01-data-model.md's
     * `CancellationPolicy` section.
     *
     * Callers are responsible for checking {@see CANCELLABLE_STATUSES}
     * themselves first (matching `DispatchBoardController::cancel()`'s
     * existing shape, which flashes its own "can no longer be cancelled"
     * toast before ever reaching this method) — this method does not
     * re-check eligibility or no-op silently, so do not call it against a
     * booking that isn't currently cancellable.
     */
    public function cancel(Booking $booking, User $actor): void
    {
        $previousStatus = $booking->status;

        DB::transaction(function () use ($booking, $previousStatus, $actor): void {
            $booking->forceFill([
                'status' => BookingStatus::Cancelled,
                'hold_expires_at' => null,
            ])->save();

            // See Booking::releasePromotionHolds()'s docblock — a
            // staff-initiated cancel must free the booking's promo-stock
            // allocation too, same as the customer-facing cancel endpoint.
            $booking->releasePromotionHolds();

            AuditLog::create([
                'auditable_type' => Booking::class,
                'auditable_id' => $booking->id,
                'action' => 'bookings.cancelled',
                'actor_id' => $actor->id,
                'before' => ['status' => $previousStatus->value],
                'after' => ['status' => BookingStatus::Cancelled->value],
            ]);
        });
    }
}
