<?php

namespace App\Http\Controllers\Api\V1\Bookings;

use App\Enums\BookingStatus;
use App\Enums\PromotionRedemptionStatus;
use App\Enums\VehicleFitmentPosition;
use App\Http\Controllers\Concerns\AuthorizesBookingAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Bookings\RescheduleBookingRequest;
use App\Http\Requests\Api\Bookings\StoreBookingRequest;
use App\Jobs\ReleaseExpiredBookingHold;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\PromotionRedemption;
use App\Models\ServiceZone;
use App\Services\Bookings\BookingLineItemInput;
use App\Services\Bookings\DurationCalculationService;
use App\Services\Bookings\FlexibleBookingPolicy;
use App\Services\Bookings\SlotComputationService;
use App\Services\Commerce\CartItemInput;
use App\Services\Promotions\AppliedPromotion;
use App\Services\Promotions\PromotionEvaluationResult;
use App\Services\Promotions\PromotionEvaluationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `POST /api/v1/bookings`, `PATCH .../reschedule`, `POST .../cancel` — see
 * docs/architecture/02-api-contract.md's "Booking & capacity endpoints"
 * section and docs/architecture/04-booking-capacity-engine.md's
 * "Reservation pattern" section for the hold-with-TTL/locking mechanism.
 */
class BookingController extends Controller
{
    use AuthorizesBookingAccess;

    private const SLOT_UNAVAILABLE_MESSAGE = 'This slot is no longer available, please choose another.';

    private const LOCK_SECONDS = 10;

    /**
     * Statuses a booking may still be rescheduled/cancelled from. Narrower
     * than {@see BookingStatus::occupying()} (which also includes
     * `in_progress` for slot-computation purposes) — a technician already
     * on site, or a booking already completed/cancelled/no-show/expired,
     * isn't a reschedule/cancel target. Not documented explicitly in the API
     * contract, but a defensive guard consistent with this project's "wire
     * the mechanism end-to-end" posture rather than assuming callers only
     * ever send sensible requests.
     */
    private const RESCHEDULABLE_STATUSES = [BookingStatus::PendingHold, BookingStatus::Confirmed];

    /**
     * Seconds a promo-stock lock is held for — same primitive, same
     * `{domain}-hold:{...}` key-naming convention, and the same short
     * critical-section duration as the technician-slot lock above. See
     * docs/architecture/05-promotions-pricing.md's "Promo stock-limit
     * enforcement" section.
     */
    private const PROMO_LOCK_SECONDS = 10;

    public function __construct(
        private readonly DurationCalculationService $duration,
        private readonly SlotComputationService $slots,
        private readonly PromotionEvaluationService $promotions,
        private readonly FlexibleBookingPolicy $flexiblePolicy,
    ) {}

    /**
     * Works guest or authenticated (`auth:customer` optional, not required —
     * deliberately not route middleware, since that would reject guests
     * outright; `$request->user('customer')` still resolves a bearer token
     * when one is present).
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        $existing = Booking::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return response()->json(['data' => $this->bookingPayload($existing, Booking::cachedManageToken($existing->id), includeManageTokenIssued: true)], 201);
        }

        $zone = ServiceZone::query()->find($request->integer('service_zone_id'));

        abort_if($zone === null, 404);

        $items = $this->itemsFromRequest($request);
        $addons = $request->validated('addons') ?? [];
        $durationMinutes = $this->duration->calculate($items, $addons);
        $requiresAlignment = in_array('alignment', $addons, true);

        $scheduledDate = CarbonImmutable::parse($request->validated('scheduled_date'))->startOfDay();
        $requestedSlotStart = $request->validated('slot_start');
        $flexible = $request->boolean('flexible');
        $customer = $request->user('customer');

        if ($flexible && ! $this->flexiblePolicy->isAvailable()) {
            return response()->json([
                'message' => 'Flexible booking is not available.',
                'errors' => ['flexible' => ['Flexible booking is not available.']],
            ], 422);
        }

        // Evaluated once, before any technician-slot attempt — it depends
        // only on cart contents/zone, never on which technician/slot ends
        // up winning, so there's no reason to recompute it per candidate
        // iteration below. See applyPromotionHolds()'s docblock for why the
        // *locks* for its stock-limited candidates are acquired later, just
        // before the DB transaction, rather than here.
        $cartItems = $items->map(fn (BookingLineItemInput $item): CartItemInput => new CartItemInput(
            tyreVariantId: $item->tyreVariantId,
            quantity: $item->quantity,
        ));
        $promoCode = $this->normalisePromoCode($request->validated('promo_code'));
        $promotionEvaluation = $this->promotions->evaluate($cartItems, $zone->id, null, $promoCode);

        // A typed code that cannot be applied is rejected up front rather
        // than silently dropped — the customer must know before a hold is
        // created that the price they saw in the cart will not hold.
        if ($promotionEvaluation->promoError !== null) {
            return response()->json([
                'message' => $promotionEvaluation->promoError['message'],
                'errors' => ['promo_code' => [$promotionEvaluation->promoError['message']]],
                'promo_error' => $promotionEvaluation->promoError,
            ], 422);
        }

        // Non-flexible: exactly the requested slot. Flexible: the requested
        // slot first (if given), then every other candidate slot for the
        // day in ascending order. Lazy, so slot lookups stop at the first
        // technician that can actually be locked and assigned — each attempt
        // goes through the same eligibility, lock and re-check path as a
        // normal booking (see FlexibleBookingPolicy for the capacity rule).
        $attempts = (function () use ($zone, $scheduledDate, $requestedSlotStart, $flexible, $durationMinutes, $requiresAlignment) {
            $slotStarts = $requestedSlotStart === null ? [] : [$requestedSlotStart];

            if ($flexible) {
                foreach ($this->slots->candidateSlotsForDate($zone, $scheduledDate, $durationMinutes, $requiresAlignment) as $slot) {
                    $slotStarts[] = $slot['start'];
                }

                $slotStarts = array_values(array_unique($slotStarts));
            }

            foreach ($slotStarts as $candidateSlotStart) {
                foreach ($this->slots->eligibleTechniciansForSlot($zone, $scheduledDate, $candidateSlotStart, $durationMinutes, $requiresAlignment) as $candidate) {
                    yield $candidate + ['slot_start' => $candidateSlotStart];
                }
            }
        })();

        foreach ($attempts as $candidate) {
            $slotStart = $candidate['slot_start'];

            $lock = Cache::lock($this->lockKey($candidate['technician_id'], $scheduledDate, $slotStart), self::LOCK_SECONDS);

            if (! $lock->get()) {
                continue;
            }

            try {
                if (! $this->slots->isTechnicianSlotFree($zone, $candidate['technician_id'], $scheduledDate, $slotStart, $durationMinutes, $requiresAlignment)) {
                    continue;
                }

                // Promo-stock locks: acquired here, *before* the DB
                // transaction opens, and released in the `finally` below,
                // *after* that transaction has returned (i.e. committed) —
                // mirroring exactly how the technician-slot `$lock` above
                // wraps the transaction, not the other way around. See
                // applyPromotionHolds()'s docblock for the race this
                // prevents.
                $promotionLocks = $this->acquirePromotionLocks($promotionEvaluation);

                try {
                    try {
                        [$booking, $manageToken, $created] = DB::transaction(function () use (
                            $idempotencyKey, $zone, $items, $addons, $durationMinutes, $scheduledDate, $slotStart, $candidate, $customer, $request, $promotionEvaluation, $promotionLocks, $flexible, $promoCode,
                        ) {
                            $existing = Booking::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

                            if ($existing !== null) {
                                return [$existing, Booking::cachedManageToken($existing->id), false];
                            }

                            $manageToken = null;
                            $manageTokenHash = null;

                            if ($customer === null) {
                                $manageToken = Str::random(40);
                                $manageTokenHash = Booking::hashManageToken($manageToken);
                            }

                            $booking = Booking::create([
                                'customer_id' => $customer?->id,
                                'vehicle_id' => $request->validated('vehicle_id'),
                                'service_zone_id' => $zone->id,
                                'scheduled_date' => $scheduledDate->toDateString(),
                                'slot_start' => $slotStart,
                                'slot_end' => $this->slots->slotEndTime($slotStart, $durationMinutes),
                                'technician_id' => $candidate['technician_id'],
                                'van_id' => $candidate['van_id'],
                                'status' => BookingStatus::PendingHold,
                                'duration_minutes' => $durationMinutes,
                                'addons' => $addons === [] ? null : array_values($addons),
                                'is_flexible' => $flexible,
                                'promo_code' => $promoCode,
                                'hold_expires_at' => now()->addMinutes(Booking::HOLD_TTL_MINUTES),
                                'idempotency_key' => $idempotencyKey,
                                'manage_token_hash' => $manageTokenHash,
                            ]);

                            $booking->lineItems()->createMany(
                                $items->map(fn (BookingLineItemInput $item): array => [
                                    'tyre_variant_id' => $item->tyreVariantId,
                                    'quantity' => $item->quantity,
                                    'position' => $item->position,
                                ])->all()
                            );

                            if ($manageToken !== null) {
                                Booking::cacheManageToken($booking->id, $manageToken);
                            }

                            $this->applyPromotionHolds($booking, $promotionEvaluation, $promotionLocks);

                            return [$booking, $manageToken, true];
                        });
                    } catch (UniqueConstraintViolationException) {
                        // Two truly concurrent requests can both pass the
                        // null-check above (routed to different technician
                        // candidates, each under its own per-technician lock) and
                        // both reach this insert for the same Idempotency-Key —
                        // `lockForUpdate()` on a `WHERE idempotency_key = X` that
                        // matches zero rows doesn't serialize against a
                        // concurrent insert of that same key. Whoever loses this
                        // race re-fetches the winner's now-committed row and
                        // replays it exactly like the ordinary "found existing by
                        // idempotency_key" branch above, instead of surfacing the
                        // DB's unique-constraint violation as a bare 500. Same
                        // catch-and-refetch shape as
                        // `TyreVariantRequest::after()`'s compound-uniqueness
                        // check, applied here to a race rather than a validation
                        // pass. `lockForUpdate()` (not a plain `first()`) for the
                        // same reason the pre-check above uses it: a locking read
                        // always reads the latest committed row regardless of
                        // this connection's transaction-snapshot state, where a
                        // plain read could still miss a just-committed row under
                        // REPEATABLE READ if this method is ever reached from
                        // inside a longer-lived enclosing transaction.
                        $existing = Booking::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

                        abort_if($existing === null, 500);

                        return response()->json(['data' => $this->bookingPayload($existing, Booking::cachedManageToken($existing->id), includeManageTokenIssued: true)], 201);
                    }

                    if ($created) {
                        ReleaseExpiredBookingHold::dispatch($booking->id)->delay($booking->hold_expires_at);
                    }

                    return response()->json(['data' => $this->bookingPayload($booking, $manageToken, includeManageTokenIssued: true)], 201);
                } finally {
                    foreach ($promotionLocks as $promotionLock) {
                        $promotionLock->release();
                    }
                }
            } finally {
                $lock->release();
            }
        }

        return response()->json(['message' => self::SLOT_UNAVAILABLE_MESSAGE], 409);
    }

    /**
     * Read-only current state, no side effects — added so a guest/owner's
     * "manage your booking" view can reflect authoritative state after a
     * page reload or an admin-side change (e.g. a dispatch-board
     * cancel/reassignment), rather than only ever learning it from a
     * mutation response. Reuses {@see authorizeGuestOrOwner()} verbatim, no
     * new auth logic; implicit route-model binding 404s before that auth
     * check ever runs, same "existence isn't itself sensitive" ordering as
     * `reschedule`/`cancel`. Deliberately omits `manage_token`/
     * `manage_token_issued` — those are creation-response-only fields (see
     * `bookingPayload()`'s `$includeManageTokenIssued` parameter); a GET
     * only verifies possession of the token via the header, it never
     * issues or re-surfaces the secret itself.
     */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeGuestOrOwner($request, $booking);

        return response()->json(['data' => $this->bookingPayload($booking, includeCancellationFee: true)]);
    }

    /**
     * Re-validated through the same slot-computation engine as
     * `booking-slots` — not a blind move. Cart contents aren't editable
     * here, so `duration_minutes`/`addons` carry over unchanged from the
     * existing booking.
     */
    public function reschedule(RescheduleBookingRequest $request, Booking $booking): JsonResponse
    {
        $this->authorizeGuestOrOwner($request, $booking);

        if (! in_array($booking->status, self::RESCHEDULABLE_STATUSES, true)) {
            return response()->json(['message' => __('This booking can no longer be rescheduled.')], 409);
        }

        $zone = ServiceZone::query()->findOrFail($booking->service_zone_id);
        $requiresAlignment = in_array('alignment', $booking->addons ?? [], true);
        $durationMinutes = $booking->duration_minutes;

        $scheduledDate = CarbonImmutable::parse($request->validated('scheduled_date'))->startOfDay();
        $slotStart = $request->validated('slot_start');

        $candidates = $this->slots->eligibleTechniciansForSlot(
            $zone, $scheduledDate, $slotStart, $durationMinutes, $requiresAlignment, excludeBookingId: $booking->id,
        );

        foreach ($candidates as $candidate) {
            $lock = Cache::lock($this->lockKey($candidate['technician_id'], $scheduledDate, $slotStart), self::LOCK_SECONDS);

            if (! $lock->get()) {
                continue;
            }

            try {
                $stillFree = $this->slots->isTechnicianSlotFree(
                    $zone, $candidate['technician_id'], $scheduledDate, $slotStart, $durationMinutes, $requiresAlignment, excludeBookingId: $booking->id,
                );

                if (! $stillFree) {
                    continue;
                }

                $feeAmount = $this->cancellationFeeFor($booking);

                DB::transaction(function () use ($booking, $scheduledDate, $slotStart, $durationMinutes, $candidate, $feeAmount): void {
                    $booking->forceFill([
                        'scheduled_date' => $scheduledDate->toDateString(),
                        'slot_start' => $slotStart,
                        'slot_end' => $this->slots->slotEndTime($slotStart, $durationMinutes),
                        'technician_id' => $candidate['technician_id'],
                        'van_id' => $candidate['van_id'],
                        'cancellation_fee_amount' => $feeAmount,
                    ])->save();
                });

                return response()->json(['data' => $this->bookingPayload($booking, includeCancellationFee: true)]);
            } finally {
                $lock->release();
            }
        }

        return response()->json(['message' => self::SLOT_UNAVAILABLE_MESSAGE], 409);
    }

    /**
     * Sets `status = cancelled` immediately — a cancelled booking simply
     * stops appearing in the slot engine's occupied-bookings query (see
     * `BookingStatus::occupying()`), so there's no separate "release the
     * lock" step to perform beyond the status write itself; the 10s
     * creation-time `Cache::lock()` is long gone by the time a real booking
     * gets cancelled.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeGuestOrOwner($request, $booking);

        if (! in_array($booking->status, self::RESCHEDULABLE_STATUSES, true)) {
            return response()->json(['message' => __('This booking can no longer be cancelled.')], 409);
        }

        $feeAmount = $this->cancellationFeeFor($booking);

        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'hold_expires_at' => null,
            'cancellation_fee_amount' => $feeAmount,
        ])->save();

        // A cancelled booking must free its promo-stock allocation
        // immediately too, not just an expired one — see
        // Booking::releasePromotionHolds()'s docblock.
        $booking->releasePromotionHolds();

        return response()->json(['data' => $this->bookingPayload($booking, includeCancellationFee: true)]);
    }

    /**
     * Evaluate the applicable {@see CancellationPolicy} (zone-specific,
     * falling back to the global row) against the notice given before the
     * booking's *current* scheduled slot — i.e. the appointment being
     * changed/cancelled, not any newly requested one.
     */
    private function cancellationFeeFor(Booking $booking): ?int
    {
        $policy = CancellationPolicy::forZone($booking->service_zone_id);

        if ($policy === null) {
            return null;
        }

        $currentSlot = CarbonImmutable::parse($booking->scheduled_date->toDateString().' '.$booking->slot_start);
        $now = CarbonImmutable::now();

        $minutesNotice = $currentSlot->greaterThan($now) ? (int) $now->diffInMinutes($currentSlot) : 0;

        return $policy->feeFor(intdiv($minutesNotice, 60));
    }

    private function lockKey(int $technicianId, CarbonImmutable $scheduledDate, string $slotStart): string
    {
        return "booking-slot:{$technicianId}:{$scheduledDate->toDateString()}:{$slotStart}";
    }

    /**
     * `$includeManageTokenIssued`: `store()`-only — the `manage_token`/
     * `manage_token_issued` pair is a creation-response-only convention
     * (docs/architecture/02-api-contract.md's "One-time secrets under
     * idempotent replay" section); `reschedule`/`cancel`/`show` never pass
     * this, so both keys stay entirely absent from those responses rather
     * than present-but-null/false. `manage_token_issued` reflects whether
     * this booking ever had one (`manage_token_hash !== null`, i.e. it was
     * created as a guest booking) — not whether `$manageToken` happens to
     * be non-null on this particular call, since a replay outside the
     * secret-replay window still has `manage_token_hash` set but no
     * retrievable plaintext (`manage_token_issued: true, manage_token:
     * null` — an expected outcome, not an error).
     *
     * @return array<string, mixed>
     */
    private function bookingPayload(Booking $booking, ?string $manageToken = null, bool $includeCancellationFee = false, bool $includeManageTokenIssued = false): array
    {
        $payload = [
            'id' => $booking->id,
            'status' => $booking->status->value,
            'scheduled_date' => $booking->scheduled_date->toDateString(),
            'slot_start' => $this->formatTime($booking->slot_start),
            'slot_end' => $this->formatTime($booking->slot_end),
            'duration_minutes' => $booking->duration_minutes,
            'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            'flexible' => $booking->is_flexible,
            'flexible_window' => $booking->is_flexible ? $this->flexibleWindow($booking) : null,
            'promo_code' => $booking->promo_code,
        ];

        if ($includeManageTokenIssued) {
            $payload['manage_token_issued'] = $booking->manage_token_hash !== null;
        }

        if ($manageToken !== null) {
            $payload['manage_token'] = $manageToken;
        }

        if ($includeCancellationFee) {
            $payload['cancellation_fee_amount'] = $booking->cancellation_fee_amount;
        }

        return $payload;
    }

    /**
     * The window a flexible customer agreed to (the zone's operating hours
     * that day) — the assigned `slot_start`/`slot_end` is the concrete slice.
     *
     * @return array{start: string, end: string}|null
     */
    private function flexibleWindow(Booking $booking): ?array
    {
        $zone = $booking->serviceZone ?? ServiceZone::query()->find($booking->service_zone_id);

        return $zone === null
            ? null
            : $this->slots->operatingWindowStrings($zone, CarbonImmutable::parse($booking->scheduled_date->toDateString()));
    }

    private function normalisePromoCode(?string $code): ?string
    {
        return ($code === null || trim($code) === '') ? null : strtoupper(trim($code));
    }

    private function formatTime(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /**
     * Acquire the promo-stock lock for every stock-limited promotion
     * `$evaluation` selected — **before** the caller opens its
     * `DB::transaction()`, not inside it. This is the fix for a real race
     * flagged by security-agent's Phase 5 review (2026-09-23): the lock's
     * acquire/release window must fully contain the transaction that
     * inserts the corresponding `PromotionRedemption` row, exactly like the
     * technician-slot `$lock` in {@see store()} already wraps its own
     * transaction. Nesting the lock *inside* the transaction (the original
     * shape) let its `finally` release the lock before that transaction
     * committed — a concurrent request could then acquire the now-free lock
     * and run its own stock re-check while the first request's `INSERT`
     * was still uncommitted and therefore invisible to it (standard
     * REPEATABLE READ visibility), oversell-ing `stock_limit` under real
     * concurrent timing on the same promotion. Non-stock-limited promotions
     * need no lock at all, same as before.
     *
     * @return array<int, Lock> promotion_id => acquired lock — only
     *                          entries whose lock was actually acquired; a promotion whose lock
     *                          couldn't be acquired is simply absent here, and
     *                          {@see applyPromotionHolds()} treats that identically to "not
     *                          applied" (never fails the booking over it).
     */
    private function acquirePromotionLocks(PromotionEvaluationResult $evaluation): array
    {
        $locks = [];

        foreach ($evaluation->applied as $applied) {
            if ($applied->promotion->stock_limit === null) {
                continue;
            }

            $lock = Cache::lock("promo-hold:{$applied->promotion->id}", self::PROMO_LOCK_SECONDS);

            if ($lock->get()) {
                $locks[$applied->promotion->id] = $lock;
            }
        }

        return $locks;
    }

    /**
     * For every promotion `$evaluation` selected, insert a `held`
     * `PromotionRedemption` — see docs/architecture/05-promotions-pricing.md's
     * "Promo stock-limit enforcement" section. Called from inside the
     * booking-creation `DB::transaction()`; the promo-stock locks in
     * `$acquiredLocks` were already taken by {@see acquirePromotionLocks()}
     * *before* that transaction opened (see that method's docblock) — this
     * method only re-checks/consumes them, it never acquires or releases
     * one itself. A promotion whose lock isn't present in `$acquiredLocks`
     * (contention) or whose stock re-check fails is simply skipped — the
     * booking itself always still succeeds.
     *
     * @param  array<int, Lock>  $acquiredLocks
     */
    private function applyPromotionHolds(Booking $booking, PromotionEvaluationResult $evaluation, array $acquiredLocks): void
    {
        foreach ($evaluation->applied as $applied) {
            $promotion = $applied->promotion;

            if ($promotion->stock_limit === null) {
                $this->createPromotionRedemption($booking, $applied);

                continue;
            }

            if (! array_key_exists($promotion->id, $acquiredLocks)) {
                // Lock contention — the promotion is simply not applied to
                // this booking, per the headline design decision. Never
                // fail the booking over a marketing cap.
                continue;
            }

            $consumed = $promotion->usage_count + (int) PromotionRedemption::query()
                ->where('promotion_id', $promotion->id)
                ->whereIn('status', [PromotionRedemptionStatus::Held, PromotionRedemptionStatus::Confirmed])
                ->sum('quantity');

            // Quantity-aware: this booking's own consumedQuantity must
            // still fit within the remaining allocation, not just "is
            // there any room at all" — a request for more than one unit
            // against a near-exhausted stock_limit must not oversell.
            // Corrected 2026-09-22, see PromotionEvaluationService's
            // passesStockLimitGate() docblock for the same fix and why.
            if ($consumed + $applied->consumedQuantity <= $promotion->stock_limit) {
                $this->createPromotionRedemption($booking, $applied);
            }
        }
    }

    private function createPromotionRedemption(Booking $booking, AppliedPromotion $applied): void
    {
        PromotionRedemption::create([
            'promotion_id' => $applied->promotion->id,
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'quantity' => $applied->consumedQuantity,
            // Computed eagerly here from the booking's own (immutable)
            // line items rather than deferred to webhook-confirmation time
            // — see the Phase 5 handback for why this deliberately departs
            // from a literal "set at confirmation" reading: it avoids a
            // second, later re-evaluation that could drift from what the
            // customer is actually charged, or find no matching candidate
            // at all if promotion state changed in between. The webhook
            // confirmation step re-derives the same figure from this same
            // immutable input and overwrites it, so the two can never
            // silently disagree.
            'discount_amount' => $applied->totalDiscount,
            'status' => PromotionRedemptionStatus::Held,
            'hold_expires_at' => $booking->hold_expires_at,
        ]);
    }

    /**
     * @return Collection<int, BookingLineItemInput>
     */
    private function itemsFromRequest(StoreBookingRequest $request): Collection
    {
        /** @var list<array{tyre_variant_id: int|string, quantity: int|string, position: string}> $itemsInput */
        $itemsInput = $request->validated('items') ?? [];

        return collect($itemsInput)
            ->map(fn (array $item): BookingLineItemInput => new BookingLineItemInput(
                tyreVariantId: (int) $item['tyre_variant_id'],
                quantity: (int) $item['quantity'],
                position: VehicleFitmentPosition::from($item['position']),
            ));
    }
}
