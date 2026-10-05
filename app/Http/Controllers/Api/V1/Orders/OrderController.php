<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\AddressType;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway as PaymentGatewayEnum;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Concerns\AuthorizesBookingAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Orders\StoreOrderRequest;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PriceGuaranteeClaim;
use App\Models\Suburb;
use App\Policies\OrderPolicy;
use App\Services\Bookings\FlexibleBookingPolicy;
use App\Services\Commerce\PayPalCaptureService;
use App\Services\Commerce\PricingLine;
use App\Services\Commerce\PricingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `POST /api/v1/orders`, `GET /api/v1/orders/{order}` — see
 * docs/architecture/02-api-contract.md's "Cart, Checkout & Payment
 * endpoints" section. `store()` mirrors `BookingController::store()`'s
 * Idempotency-Key-replay/race-recovery shape exactly (same pattern reused a
 * second time, not a divergent one).
 *
 * `Payment.method` (a required, non-nullable column) can't be known yet at
 * this point — Stripe's Payment Element lets the customer pick their actual
 * instrument (card/Apple Pay/Google Pay/Afterpay) at *client-side
 * confirmation*, which happens strictly after this endpoint already returns
 * `client_secret`. This controller writes `PaymentMethod::Card` as a
 * placeholder only; `StripeWebhookController::handlePaymentIntentSucceeded()`
 * resolves the real value from Stripe's own report and corrects the row
 * once the charge actually succeeds — see `PaymentMethod::fromStripe()`'s
 * docblock.
 */
class OrderController extends Controller
{
    use AuthorizesBookingAccess;

    private const MAX_ORDER_NUMBER_ATTEMPTS = 3;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly PaymentGateway $gateway,
        private readonly PayPalCaptureService $captureService,
    ) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        $existing = Order::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return response()->json(['data' => $this->replayPayload($existing)], 201);
        }

        $booking = Booking::query()->find($request->integer('booking_id'));

        abort_if($booking === null, 404);

        $this->authorizeGuestOrOwner($request, $booking);

        if ($booking->status !== BookingStatus::PendingHold || $booking->hold_expires_at === null || $booking->hold_expires_at->isPast()) {
            return response()->json(['message' => __('This booking hold has expired, please choose another appointment.')], 409);
        }

        $customer = $request->user('customer');
        $pricing = $this->pricing->priceBooking($booking, $customer);

        $order = null;
        $payment = null;
        $guestToken = null;
        $paymentDisplay = ['client_secret' => null, 'paypal_order_id' => null];

        for ($attempt = 1; $attempt <= self::MAX_ORDER_NUMBER_ATTEMPTS; $attempt++) {
            try {
                [$order, $payment, $guestToken, $paymentDisplay] = DB::transaction(function () use ($request, $booking, $pricing, $customer, $idempotencyKey) {
                    $existing = Order::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

                    if ($existing !== null) {
                        $existingPayment = $existing->payments()->where('type', PaymentType::Charge)->latest('id')->first();

                        abort_if($existingPayment === null, 500);

                        // Never regenerate the PaymentIntent/PayPal Order on
                        // a replay — reuse the stored gateway_reference.
                        return [$existing, $existingPayment, Order::cachedGuestToken($existing->id), $this->replayPaymentDisplay($existingPayment)];
                    }

                    $customerId = $this->resolveCustomerId($request, $customer);
                    $address = $this->createAddress($request, $customerId);

                    $order = Order::create([
                        'order_number' => $this->generateOrderNumber(),
                        'customer_id' => $customerId,
                        'booking_id' => $booking->id,
                        'address_id' => $address->id,
                        'status' => OrderStatus::PendingPayment,
                        'payment_status' => PaymentStatus::Pending,
                        'subtotal' => $pricing->subtotal,
                        'discount_total' => $pricing->discountTotal,
                        'flexible_discount_total' => $pricing->flexibleDiscount,
                        'tax_total' => $pricing->taxTotal,
                        'service_fee_total' => $pricing->serviceFeeTotal,
                        'grand_total' => $pricing->grandTotal,
                        'currency' => $pricing->currency,
                        'fitting_details' => $this->fittingDetails($request),
                        'idempotency_key' => $idempotencyKey,
                        'placed_at' => now(),
                    ]);

                    if ($request->boolean('newsletter_opt_in')) {
                        NewsletterSubscriber::subscribe((string) $request->validated('customer.email'), null, 'checkout', $request->ip());
                    }

                    $order->lineItems()->createMany(
                        array_map(fn (PricingLine $line): array => [
                            'tyre_variant_id' => $line->tyreVariantId,
                            'quantity' => $line->quantity,
                            'unit_price' => $line->unitPrice,
                            'discount_amount' => $line->discountAmount,
                            'tax_amount' => $line->taxAmount,
                            'line_total' => $line->lineTotal,
                        ], $pricing->lines)
                    );

                    // Price-guarantee claims are additive, not part of the
                    // Promotion mutual-exclusivity pool, and — unlike
                    // PromotionRedemption — have no stock/scarcity risk to
                    // defer confirmation for, so they're stamped the moment
                    // the order is actually placed, in this same
                    // transaction, not at webhook time. See
                    // docs/architecture/05-promotions-pricing.md's
                    // "Price-guarantee claim workflow" section.
                    if ($pricing->appliedPriceGuaranteeClaimIds !== []) {
                        PriceGuaranteeClaim::query()
                            ->whereIn('id', $pricing->appliedPriceGuaranteeClaimIds)
                            ->update(['redeemed_at' => now(), 'order_id' => $order->id]);
                    }

                    // booking.status stays pending_hold — only the webhook,
                    // on payment success, moves it to confirmed. Checkout's
                    // vehicle capture (requirements §6) takes precedence
                    // over whatever vehicle_id (if any) was already resolved
                    // at booking-creation time, falling back to it only when
                    // this request doesn't supply one.
                    $vehicleId = $request->validated('vehicle.vehicle_id');

                    $booking->forceFill([
                        'address_id' => $address->id,
                        'order_id' => $order->id,
                        'vehicle_id' => $vehicleId ?? $booking->vehicle_id,
                    ])->save();

                    $guestToken = null;

                    if ($customer === null) {
                        $guestToken = Str::random(40);
                        $order->forceFill(['guest_token_hash' => Order::hashGuestToken($guestToken)])->save();
                        Order::cacheGuestToken($order->id, $guestToken);
                    }

                    // Whichever gateway is currently active — correct by
                    // definition for a brand-new payment (see this class's
                    // docblock and docs/architecture/03-integrations.md's
                    // PayPal section, point 3).
                    $activeGateway = PaymentGatewayEnum::from((string) config('services.payment_gateway'));

                    $intent = $this->gateway->createPaymentIntent($pricing->grandTotal, Str::lower($order->currency), [
                        'order_id' => (string) $order->id,
                        'booking_id' => (string) $booking->id,
                    ]);

                    $payment = Payment::create([
                        'order_id' => $order->id,
                        'type' => PaymentType::Charge,
                        // Reflects whichever gateway actually created this
                        // payment intent — never hardcoded.
                        'gateway' => $activeGateway,
                        // See this class's docblock — the real instrument
                        // isn't known until client-side confirmation
                        // (Stripe) / the paypal-capture step (PayPal);
                        // StripeWebhookController/PayPalCaptureService
                        // correct this once the charge actually succeeds.
                        'method' => PaymentMethod::Card,
                        'status' => PaymentTransactionStatus::Pending,
                        'amount' => $pricing->grandTotal,
                        'gateway_reference' => $intent->id,
                    ]);

                    $paymentDisplay = $activeGateway === PaymentGatewayEnum::PayPal
                        ? ['client_secret' => null, 'paypal_order_id' => $intent->id]
                        : ['client_secret' => $intent->clientSecret, 'paypal_order_id' => null];

                    return [$order, $payment, $guestToken, $paymentDisplay];
                });

                break;
            } catch (UniqueConstraintViolationException $e) {
                // Mirrors BookingController::store()'s identical recovery
                // shape: a genuinely concurrent request for the same
                // Idempotency-Key can lose the race between this method's
                // pre-checks and its own INSERT. Re-fetch and replay the
                // winner instead of surfacing a raw 500. A collision on the
                // separately-generated `order_number` instead (astronomically
                // rarer, no idempotency_key match to recover via) retries
                // with a freshly generated number, up to
                // MAX_ORDER_NUMBER_ATTEMPTS.
                $existing = Order::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

                if ($existing !== null) {
                    $existingPayment = $existing->payments()->where('type', PaymentType::Charge)->latest('id')->first();

                    abort_if($existingPayment === null, 500);

                    $order = $existing;
                    $payment = $existingPayment;
                    $guestToken = Order::cachedGuestToken($existing->id);
                    $paymentDisplay = $this->replayPaymentDisplay($existingPayment);
                    break;
                }

                if ($attempt === self::MAX_ORDER_NUMBER_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        abort_if($order === null, 500);

        return response()->json(['data' => $this->orderPayload($order, $payment, $paymentDisplay, $guestToken)], 201);
    }

    /**
     * `POST /api/v1/orders/{order}/paypal-capture` — PayPal only. Called by
     * the frontend's PayPal Buttons' `onApprove` callback immediately after
     * the customer approves — capture must happen server-side, never
     * client-side. Same auth as `show()` (authenticated owner or
     * `X-Order-Token`), guarded by the `idempotency` middleware (same
     * convention as `store()`). See
     * docs/architecture/02-api-contract.md's
     * `POST /api/v1/orders/{order}/paypal-capture` section.
     */
    public function paypalCapture(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $idempotencyKey = (string) $request->header('Idempotency-Key');

        $outcome = $this->captureService->captureAndConfirm($order, $idempotencyKey);

        if (! $outcome->succeeded) {
            return response()->json(['message' => $outcome->declineReason ?? __('PayPal declined this payment. Please try another PayPal funding source.')], 422);
        }

        $order->refresh()->load(['lineItems', 'booking']);

        return response()->json(['data' => $this->orderPayload($order, payment: null, paymentDisplay: null, guestToken: null, includeLineItemsAndBooking: true)]);
    }

    /**
     * Read-only current state. Mirrors `BookingController::show()`'s auth
     * ordering exactly: `404` before the auth check (existence isn't itself
     * sensitive), then authenticated owner (`auth:customer` +
     * {@see OrderPolicy}) or a guest presenting a matching `X-Order-Token`.
     * Never returns `order_token`/`client_secret`/`paypal_order_id` —
     * creation-response-only.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $order->load(['lineItems', 'booking']);

        return response()->json(['data' => $this->orderPayload($order, payment: null, paymentDisplay: null, guestToken: null, includeLineItemsAndBooking: true)]);
    }

    private function authorizeOrderAccess(Request $request, Order $order): void
    {
        $customer = $request->user('customer');

        if ($customer !== null) {
            abort_if(Gate::forUser($customer)->denies('view', $order), 403);

            return;
        }

        $token = $request->header('X-Order-Token');

        abort_if(! is_string($token) || $token === '' || ! $order->guestTokenMatches($token), 403);
    }

    private function resolveCustomerId(StoreOrderRequest $request, ?Customer $customer): int
    {
        if ($customer !== null) {
            return $customer->id;
        }

        $email = $request->validated('customer.email');

        $customerRow = Customer::query()->where('email', $email)->first();

        $customerRow ??= Customer::create([
            'name' => $request->validated('customer.name'),
            'email' => $email,
            'mobile' => $request->validated('customer.mobile'),
        ]);

        return $customerRow->id;
    }

    /**
     * The optional vehicle/fitting extras from the checkout wizard (colour,
     * make/model, which wheels, fitter notes), or null when none were sent.
     * Stored as JSON on the order for the admin panel; none of it affects
     * pricing.
     *
     * @return array<string, mixed>|null
     */
    private function fittingDetails(StoreOrderRequest $request): ?array
    {
        $details = array_filter([
            'rego' => $request->validated('vehicle.rego'),
            'rego_state' => $request->validated('vehicle.state'),
            'make' => $request->validated('vehicle.make'),
            'model' => $request->validated('vehicle.model'),
            'colour' => $request->validated('vehicle.colour'),
            'year' => $request->validated('vehicle.year'),
            'wheels' => $request->validated('vehicle.wheels'),
            'notes' => $request->validated('notes'),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return $details === [] ? null : $details;
    }

    /**
     * `Address.postcode` isn't part of the documented request body (only
     * `suburb_id`/`line1`/`line2`/`lat`/`lng`/`access_instructions` are) —
     * derived from the linked `Suburb.postcode` instead of asking the client
     * to re-supply a value that's already implied by `suburb_id`.
     */
    private function createAddress(StoreOrderRequest $request, int $customerId): Address
    {
        $suburb = Suburb::query()->findOrFail($request->integer('address.suburb_id'));

        return Address::create([
            'customer_id' => $customerId,
            'suburb_id' => $suburb->id,
            'line1' => $request->validated('address.line1'),
            'line2' => $request->validated('address.line2'),
            'postcode' => $suburb->postcode,
            'lat' => $request->validated('address.lat'),
            'lng' => $request->validated('address.lng'),
            'access_instructions' => $request->validated('address.access_instructions'),
            'type' => AddressType::Fitting,
        ]);
    }

    private function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $count = Order::query()->where('order_number', 'like', "TMS-{$date}-%")->count();

        return sprintf('TMS-%s-%04d', $date, $count + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function replayPayload(Order $order): array
    {
        $payment = $order->payments()->where('type', PaymentType::Charge)->latest('id')->first();

        abort_if($payment === null, 500);

        return $this->orderPayload($order, $payment, $this->replayPaymentDisplay($payment), Order::cachedGuestToken($order->id));
    }

    /**
     * Resolves the response's `payment.client_secret`/`payment.paypal_order_id`
     * pair for an `Idempotency-Key` replay, keyed off the *already-persisted*
     * `Payment` row's own `gateway` — a replay never creates a second
     * `PaymentIntent`/PayPal Order. Stripe: `client_secret` is re-fetched
     * (safe, doesn't rotate — a real Stripe round trip via the active
     * `$this->gateway` singleton, an accepted exception for this narrow
     * replay window, see docs/architecture/03-integrations.md's PayPal
     * section, point 3); PayPal: `paypal_order_id` is simply re-surfaced
     * unchanged — nothing to re-fetch, the id doesn't rotate either.
     *
     * @return array{client_secret: ?string, paypal_order_id: ?string}
     */
    private function replayPaymentDisplay(Payment $payment): array
    {
        return match ($payment->gateway) {
            PaymentGatewayEnum::Stripe => [
                'client_secret' => $this->gateway->retrievePaymentIntent($payment->gateway_reference)->clientSecret,
                'paypal_order_id' => null,
            ],
            PaymentGatewayEnum::PayPal => [
                'client_secret' => null,
                'paypal_order_id' => $payment->gateway_reference,
            ],
            PaymentGatewayEnum::Zip => throw new RuntimeException('Zip is a reserved gateway enum value with no PaymentGateway implementation yet.'),
        };
    }

    /**
     * @param  array{client_secret: ?string, paypal_order_id: ?string}|null  $paymentDisplay
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order, ?Payment $payment, ?array $paymentDisplay, ?string $guestToken, bool $includeLineItemsAndBooking = false): array
    {
        $payload = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'subtotal' => $order->subtotal,
            'discount_total' => $order->discount_total,
            // Already included in `discount_total`; itemised so the UI can show
            // a labelled line (null when the booking was not flexible).
            'flexible_discount' => $order->flexible_discount_total > 0
                ? ['label' => app(FlexibleBookingPolicy::class)->lineLabel(), 'amount' => $order->flexible_discount_total]
                : null,
            'tax_total' => $order->tax_total,
            'service_fee_total' => $order->service_fee_total,
            'grand_total' => $order->grand_total,
            'currency' => $order->currency,
        ];

        // order_token_issued/order_token/payment.* are creation-response-only
        // — GET (and the paypal-capture response) never surfaces them (see
        // docs/architecture/02-api-contract.md's `GET /api/v1/orders/{order}`
        // section), signalled here by whether a $payment was passed at all.
        if ($payment !== null) {
            $payload['order_token_issued'] = $order->guest_token_hash !== null;

            if ($guestToken !== null) {
                $payload['order_token'] = $guestToken;
            }

            // Always the same three keys regardless of active gateway —
            // whichever half doesn't apply is null, discriminated by
            // `gateway`. See docs/architecture/02-api-contract.md's
            // `POST /api/v1/orders` response shape.
            $payload['payment'] = [
                'gateway' => $payment->gateway->value,
                'client_secret' => $paymentDisplay['client_secret'] ?? null,
                'paypal_order_id' => $paymentDisplay['paypal_order_id'] ?? null,
            ];
        }

        if ($includeLineItemsAndBooking) {
            $payload['line_items'] = $order->lineItems->map(fn ($lineItem): array => [
                'tyre_variant_id' => $lineItem->tyre_variant_id,
                'quantity' => $lineItem->quantity,
                'unit_price' => $lineItem->unit_price,
                'discount_amount' => $lineItem->discount_amount,
                'tax_amount' => $lineItem->tax_amount,
                'line_total' => $lineItem->line_total,
            ])->all();

            $booking = $order->booking;

            $payload['booking'] = $booking === null ? null : [
                'scheduled_date' => $booking->scheduled_date->toDateString(),
                'slot_start' => substr($booking->slot_start, 0, 5),
                'slot_end' => substr($booking->slot_end, 0, 5),
            ];
        }

        return $payload;
    }
}
