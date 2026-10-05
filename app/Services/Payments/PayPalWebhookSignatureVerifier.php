<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal has no local-HMAC webhook-verification equivalent to
 * `Stripe\Webhook::constructEvent()` — it requires a server-to-server call
 * to `/v1/notifications/verify-webhook-signature`. Deliberately a raw `Http`
 * facade wrapper, not routed through `paypal/paypal-server-sdk` (that
 * package covers the Orders v2/Payments v2 checkout APIs only — Webhooks
 * Management isn't part of it), same "code against the documented REST
 * contract directly" posture as `App\Services\Notifications\MessageMediaClient`.
 * See docs/architecture/02-api-contract.md's `POST /api/v1/webhooks/paypal`
 * section.
 *
 * The OAuth client-credentials token this needs is fetched and cached via
 * `Cache` (cross-request, keyed by the token's own reported `expires_in`) —
 * deliberately NOT the same token `PayPalPaymentGateway`'s SDK client
 * manages internally, since that caching lives only for the lifetime of one
 * PHP request/container and would otherwise re-fetch a token on every
 * single webhook delivery.
 */
class PayPalWebhookSignatureVerifier
{
    private const TOKEN_CACHE_KEY = 'paypal-webhook-oauth-token';

    /**
     * Seconds subtracted from the token's own reported `expires_in` before
     * caching, so a request that lands right at the boundary never presents
     * an already-expired token to the verify call.
     */
    private const TOKEN_EXPIRY_BUFFER_SECONDS = 30;

    /**
     * `$webhookEvent` must be the incoming request body decoded once
     * (`json_decode($request->getContent(), true)`) and passed through
     * unmodified — PayPal's own documented requirement for this field, see
     * this class's docblock and
     * docs/architecture/02-api-contract.md's webhook-verification contract.
     *
     * @param  array<string, mixed>  $webhookEvent
     */
    public function verify(Request $request, array $webhookEvent): bool
    {
        $accessToken = $this->accessToken();

        if ($accessToken === null) {
            return false;
        }

        $baseUrl = $this->baseUrl();

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(10)
            ->post("{$baseUrl}/v1/notifications/verify-webhook-signature", [
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'webhook_id' => (string) config('services.paypal.webhook_id'),
                'webhook_event' => $webhookEvent,
            ]);

        if (! $response->successful()) {
            Log::warning('PayPal webhook signature verification call failed.', ['status' => $response->status()]);

            return false;
        }

        // The only pass condition — anything else (including a non-2xx
        // response above) is treated as a failed verification, never
        // processed. See docs/architecture/02-api-contract.md's webhook
        // section, point 3.
        return $response->json('verification_status') === 'SUCCESS';
    }

    /**
     * Client-credentials grant against `/v1/oauth2/token`, cached under its
     * own reported `expires_in` — never re-fetched per webhook delivery.
     */
    private function accessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withBasicAuth((string) config('services.paypal.client_id'), (string) config('services.paypal.client_secret'))
            ->timeout(10)
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (! $response->successful()) {
            Log::warning('PayPal webhook verification: failed to obtain an OAuth token.', ['status' => $response->status()]);

            return null;
        }

        $accessToken = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        $ttlSeconds = is_int($expiresIn) && $expiresIn > self::TOKEN_EXPIRY_BUFFER_SECONDS
            ? $expiresIn - self::TOKEN_EXPIRY_BUFFER_SECONDS
            : 60;

        Cache::put(self::TOKEN_CACHE_KEY, $accessToken, $ttlSeconds);

        return $accessToken;
    }

    /**
     * Same sandbox-vs-live selection as `PayPalPaymentGateway`'s SDK client
     * (`config('services.paypal.mode')`) — must always agree with which
     * `client_id`/`client_secret`/`webhook_id` triple is configured. See
     * config('services.paypal')'s own docblock.
     */
    private function baseUrl(): string
    {
        $mode = (string) config('services.paypal.mode', 'sandbox');

        return $mode === 'production' || $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
