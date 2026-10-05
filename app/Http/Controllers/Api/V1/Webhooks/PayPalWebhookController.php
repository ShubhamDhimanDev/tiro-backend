<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Commerce\PayPalCaptureService;
use App\Services\Payments\PayPalWebhookSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/v1/webhooks/paypal` — PayPal-facing, not storefront-facing.
 * Excluded from Sanctum auth and the `Idempotency-Key` middleware (PayPal's
 * caller sends neither) — signature verification below is this route's
 * actual trust boundary, same shape as `StripeWebhookController`'s, a
 * materially different mechanism. See
 * docs/architecture/02-api-contract.md's `POST /api/v1/webhooks/paypal`
 * section for the full design this implements.
 *
 * An eventual-consistency **backstop only** — the synchronous
 * `POST /api/v1/orders/{order}/paypal-capture` endpoint is the primary path
 * for `PAYMENT.CAPTURE.COMPLETED`. Depends on the concrete
 * {@see PayPalCaptureService} directly (which itself depends on the
 * concrete `PayPalPaymentGateway`), never the generic active-gateway
 * `PaymentGateway` interface — see
 * docs/architecture/03-integrations.md's PayPal section, point 3: a webhook
 * for an old PayPal order must resolve correctly even if `PAYMENT_GATEWAY`
 * has since switched to `stripe`.
 */
class PayPalWebhookController extends Controller
{
    /**
     * PayPal's own redelivery window — same role as
     * `StripeWebhookController::EVENT_DEDUP_TTL_HOURS`, a materially
     * different constant from the 15-minute guest-secret-replay window
     * elsewhere in this codebase.
     */
    private const EVENT_DEDUP_TTL_HOURS = 24;

    public function __construct(
        private readonly PayPalWebhookSignatureVerifier $verifier,
        private readonly PayPalCaptureService $captureService,
    ) {}

    public function handle(Request $request): Response
    {
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! isset($payload['id']) || ! is_string($payload['id'])) {
            Log::warning('PayPal webhook: payload was not valid JSON or carried no event id.');

            return response('', 400);
        }

        // Real server-to-server signature verification — never
        // skipped/faked. See PayPalWebhookSignatureVerifier's docblock.
        if (! $this->verifier->verify($request, $payload)) {
            Log::warning('PayPal webhook signature verification failed.', ['event_id' => $payload['id']]);

            return response('', 400);
        }

        $eventId = $payload['id'];

        // Replay-safety layer 1 (cache-based dedup on event id, 24h —
        // PayPal's own redelivery window). Only a presence *check* here —
        // deliberately NOT marked "handled" until the dispatch below
        // completes without throwing (see the `Cache::put()` call at the
        // bottom of this method). This is the exact bug this project
        // already shipped and fixed once for Stripe in Phase 4 — see
        // `.claude/agent-memory/security-agent/feedback_webhook_cache_ordering.md`
        // and `StripeWebhookController`'s own identical comment: marking an
        // event handled *before* processing means a legitimate handler
        // throw (an unmapped `PaymentMethod::fromPayPal()` value,
        // structurally the same risk as Stripe's own `fromStripe()`)
        // permanently blocks PayPal's automatic redelivery from ever
        // reprocessing it, stranding a genuinely-paid order. Build correctly
        // from the start rather than needing a second fix-then-reverify
        // pass.
        if (Cache::has(self::eventCacheKey($eventId))) {
            return response('', 200);
        }

        $resource = $payload['resource'] ?? null;

        // Deliberately not wrapped in try/catch — a thrown exception here
        // (e.g. an unmapped payment_source type) must propagate to
        // Laravel's default exception handling (a 500) so PayPal retries
        // delivery, and must leave this event id unmarked so that retry
        // actually reprocesses it. Every other event type is a deliberate
        // no-op, same posture as Stripe's own `default => null` match arm.
        match ($payload['event_type'] ?? null) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->captureService->confirmFromWebhookResource(is_array($resource) ? $resource : []),
            'PAYMENT.CAPTURE.DENIED' => $this->handleCaptureDenied(is_array($resource) ? $resource : []),
            'PAYMENT.CAPTURE.REFUNDED' => null, // reconciliation backstop only — see this class's docblock; nothing to reconcile against yet beyond what the synchronous admin refund flow already records.
            'CHECKOUT.ORDER.APPROVED' => null, // informational only, deliberate no-op — capture is triggered synchronously by the frontend, not by this event.
            default => null,
        };

        // Only mark handled once the dispatch above has completed
        // successfully.
        Cache::put(self::eventCacheKey($eventId), true, now()->addHours(self::EVENT_DEDUP_TTL_HOURS));

        return response('', 200);
    }

    private static function eventCacheKey(string $eventId): string
    {
        return "paypal-webhook-event:{$eventId}";
    }

    /**
     * Mirrors `StripeWebhookController::handlePaymentIntentFailed()` — the
     * booking hold is left untouched, it simply continues toward its own
     * natural TTL expiry via the existing sweep.
     *
     * @param  array<string, mixed>  $resource
     */
    private function handleCaptureDenied(array $resource): void
    {
        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        if (! is_string($paypalOrderId)) {
            Log::warning('PayPal webhook: PAYMENT.CAPTURE.DENIED resource carried no order id.', ['resource' => $resource]);

            return;
        }

        $this->captureService->markDeniedByWebhook($paypalOrderId, $resource);
    }
}
