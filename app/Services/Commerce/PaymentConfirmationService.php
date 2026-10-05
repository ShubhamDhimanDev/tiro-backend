<?php

namespace App\Services\Commerce;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PromotionRedemptionStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Services\Promotions\AppliedPromotion;

/**
 * Shared "confirm Booking/Order state once a charge has actually succeeded"
 * logic — extracted from `StripeWebhookController::handlePaymentIntentSucceeded()`
 * (Phase 4) so it has exactly one implementation across all three call sites
 * that now need it: the Stripe webhook, PayPal's synchronous
 * `paypal-capture` endpoint, and PayPal's webhook backstop (see
 * `App\Services\Commerce\PayPalCaptureService`). Stripe only ever needed
 * this once inline; leaving it inline would have meant PayPal's two call
 * sites either duplicating it or drifting — the exact "shipped twice,
 * drifted twice" bug class this project already guards against elsewhere.
 * See docs/architecture/03-integrations.md's PayPal section, point 6.
 *
 * Gateway-agnostic by design: callers are responsible for their own
 * locking/transaction scope and for having already persisted
 * `Payment.status = succeeded` (and, for PayPal, `gateway_capture_reference`)
 * before calling `confirm()` — this service only owns the
 * Booking/Order/PromotionRedemption side of the state transition.
 */
class PaymentConfirmationService
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * Confirm `Booking`+`Order` if the hold is still live; otherwise this is
     * the rare-but-real "payment confirmed after the hold expired" edge
     * case — `Order.status = refund_required` plus an `AuditLog` row, never
     * silently unreachable. See
     * docs/architecture/01-data-model.md's `Order.refund_required` note.
     *
     * Locks and re-fetches `$order`'s linked `Booking` itself — callers
     * must already be inside a transaction with `$order` (and its `Payment`
     * row) locked/saved before calling this.
     */
    public function confirm(Order $order): void
    {
        $booking = Booking::query()->whereKey($order->booking_id)->lockForUpdate()->first();

        $holdStillLive = $booking !== null
            && $booking->status === BookingStatus::PendingHold
            && $booking->hold_expires_at !== null
            && $booking->hold_expires_at->isFuture();

        if ($holdStillLive) {
            $order->forceFill(['payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Confirmed])->save();

            $booking->forceFill(['status' => BookingStatus::Confirmed, 'hold_expires_at' => null])->save();

            $this->confirmPromotionRedemptions($booking, $order);

            return;
        }

        $beforeStatus = $order->status;

        $order->forceFill(['payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::RefundRequired])->save();

        AuditLog::create([
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'action' => 'orders.payment_confirmed_after_hold_expired',
            'actor_id' => null,
            'before' => ['status' => $beforeStatus->value, 'booking_status' => $booking?->status->value],
            'after' => ['status' => OrderStatus::RefundRequired->value],
        ]);
    }

    /**
     * Flip every `held` {@see PromotionRedemption} for
     * `$booking` to `confirmed` — stamping `order_id`/`redeemed_at` and
     * incrementing `Promotion.usage_count` — in the same transaction that
     * confirms `Booking`/`Order`, per
     * docs/architecture/05-promotions-pricing.md's "Promo stock-limit
     * enforcement" section. `discount_amount` is re-derived by re-running
     * the (hold-aware) promotion evaluation against this booking's immutable
     * line items, one more time, rather than trusted blindly from
     * hold-creation time — this is the "stamped at confirmation" step the
     * docs describe. A held redemption whose promotion no longer appears in
     * that fresh evaluation (e.g. an admin deactivated/edited it between
     * order-creation and confirmation — the same small-race window this
     * project already accepts for `usage_limit` elsewhere) keeps whatever
     * `discount_amount` was computed at hold-creation time rather than being
     * zeroed out: the customer was already charged based on the order total
     * that included it, so silently dropping the recorded discount here
     * would be worse than a rare, already-accepted staleness window.
     */
    private function confirmPromotionRedemptions(Booking $booking, Order $order): void
    {
        $held = $booking->promotionRedemptions()->held()->get();

        if ($held->isEmpty()) {
            return;
        }

        $evaluation = $this->pricing->evaluatePromotionsForBooking($booking);

        /** @var array<int, AppliedPromotion> $byPromotionId */
        $byPromotionId = [];

        foreach ($evaluation->applied as $applied) {
            $byPromotionId[$applied->promotion->id] = $applied;
        }

        foreach ($held as $redemption) {
            // array_key_exists(), not `$byPromotionId[$key] ?? null` — the
            // latter's offset-access type on a plain `array<int,
            // AppliedPromotion>` doesn't let phpstan see the "key absent"
            // branch as reachable, which would silently defeat the
            // fallback-to-hold-time-amount behavior documented above.
            $fresh = array_key_exists($redemption->promotion_id, $byPromotionId)
                ? $byPromotionId[$redemption->promotion_id]
                : null;

            $redemption->forceFill([
                'status' => PromotionRedemptionStatus::Confirmed,
                'order_id' => $order->id,
                'redeemed_at' => now(),
                'discount_amount' => $fresh === null ? $redemption->discount_amount : $fresh->totalDiscount,
            ])->save();

            Promotion::query()->whereKey($redemption->promotion_id)->increment('usage_count');
        }
    }
}
