<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | Cards, Apple Pay, Google Pay, and native Afterpay all route through
    | Stripe — see docs/architecture/03-integrations.md item 3. Test-mode
    | keys are self-serve/free (dashboard.stripe.com, toggle test mode); only
    | *going live* with Afterpay needs merchant approval (see
    | docs/architecture/06-open-decisions.md item 5). `webhook_secret` is a
    | distinct credential from `secret`, obtained per-endpoint from the
    | dashboard (or the Stripe CLI's own secret for local `stripe listen`
    | testing) — see App\Http\Controllers\Api\V1\Webhooks\StripeWebhookController.
    |
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'publishable' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Active payment gateway selector
    |--------------------------------------------------------------------------
    |
    | Top-level, not nested under `stripe`/`paypal` — this is the selector,
    | not a credential. Drives `App\Providers\AppServiceProvider::register()`'s
    | `App\Contracts\Payments\PaymentGateway` binding (which concrete gateway
    | handles a *brand-new* payment — see `App\Services\Payments\PaymentGatewayResolver`'s
    | docblock for why an already-persisted `Payment` row's own `gateway`
    | column is resolved separately, never off this config value). Valid
    | values: `stripe`, `paypal` (matching `App\Enums\PaymentGateway`'s two
    | built cases — `zip` stays reserved/unbuilt). An unrecognized value is a
    | hard `RuntimeException` at boot — fail loud, not a silent fallback to
    | Stripe. See docs/architecture/03-integrations.md's PayPal section,
    | point 1.
    |
    */
    'payment_gateway' => env('PAYMENT_GATEWAY', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | PayPal
    |--------------------------------------------------------------------------
    |
    | The second payment gateway, alongside Stripe — see
    | docs/architecture/03-integrations.md's PayPal section. `mode` selects
    | PayPal's REST base URL (sandbox vs. live, `App\Services\Payments\PayPalPaymentGateway`)
    | and must always agree with which `client_id`/`client_secret`/
    | `webhook_id` triple is currently configured — a mismatch here means
    | every API call/webhook-verify silently fails against the wrong
    | environment. `webhook_id` is obtained from the PayPal Developer
    | Dashboard when registering the webhook URL, one per environment — see
    | App\Http\Controllers\Api\V1\Webhooks\PayPalWebhookController and
    | App\Services\Payments\PayPalWebhookSignatureVerifier. No live (or
    | sandbox) PayPal credentials exist in this workspace as of this pass —
    | see docs/architecture/06-open-decisions.md item 24 — so these three
    | commonly stay blank; every call site is covered by
    | `Http::fake()`/a fake gateway double in tests, never a live call
    | without them.
    |
    */
    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend (Next.js) on-demand ISR revalidation webhook
    |--------------------------------------------------------------------------
    |
    | Phase 6 admin CMS/catalogue changes queue a job that POSTs here with
    | header `X-Revalidate-Secret: revalidate_secret` and body
    | `{ "tags": [...] }` so Next.js can call revalidateTag() per tag. See
    | docs/architecture (project-architect's Phase 6 readiness-pass) for the
    | full contract. `revalidate_url` always resolves to
    | `{next-js-origin}/api/revalidate` — in local dev that's the frontend's
    | native `next dev` origin (http://localhost:3000), matching the same
    | plain-localhost convention the reverse direction already uses
    | (frontend's LARAVEL_API_URL defaults to http://localhost:8000) since
    | neither app runs inside the `app`/`node` Compose services yet (see
    | ../docker-compose.yml's header — both are still `profiles: [staged]`).
    | Revisit to the `http://node:3000/api/revalidate` compose-network form
    | once that cutover happens. `revalidate_secret` must be byte-for-byte
    | identical to frontend's REVALIDATE_WEBHOOK_SECRET (two different env
    | var names/repos holding one shared value) — a mismatch here means every
    | webhook call silently 401s, masked by the existing timed ISR window.
    |
    */
    'frontend' => [
        'revalidate_url' => env('FRONTEND_REVALIDATE_URL'),
        'revalidate_secret' => env('FRONTEND_REVALIDATE_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MessageMedia (SMS)
    |--------------------------------------------------------------------------
    |
    | Phase 7 — `App\Services\Notifications\MessageMediaClient`, the only
    | `App\Contracts\Notifications\SmsProvider` implementor this phase (see
    | its docblock: Twilio is explicitly not being built). Authenticates via
    | HTTP Basic Auth against MessageMedia's Messages REST API
    | (`api_key`/`api_secret` are the Basic Auth username/password — "API
    | key"/"API secret" in MessageMedia's own dashboard terminology, not an
    | HMAC pair). `base_url` defaults to the documented production endpoint;
    | override only for a sandbox/mock host in a non-production environment.
    | MessageMedia sandbox/developer-account access is unconfirmed on the
    | business side as of this phase — see this phase's task brief — so
    | these three vars may stay unset in every environment for now; every
    | call site is covered by `Http::fake()` in tests, never a live call.
    |
    */
    'messagemedia' => [
        'api_key' => env('MESSAGEMEDIA_API_KEY'),
        'api_secret' => env('MESSAGEMEDIA_API_SECRET'),
        'base_url' => env('MESSAGEMEDIA_BASE_URL', 'https://api.messagemedia.com/v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Business Profile reviews sync
    |--------------------------------------------------------------------------
    |
    | Phase 8 — App\Console\Commands\SyncGoogleReviewsCommand, against the
    | Business Profile APIs' `accounts.locations.reviews.list` endpoint
    | (NOT the Places API's `reviews` field, whose ToS forbids caching
    | review content — see docs/architecture/03-integrations.md item 5).
    | Auth is OAuth 2.0 user-delegated (3-legged) consent, materially
    | different from every other integration here: a real person managing
    | Tiro's GBP listing has to click through a one-time consent grant
    | (`business.manage` scope) before `refresh_token` exists — external
    | lead time, tracked separately, not blocking this command's build/test
    | (every call site is covered by Http::fake() in tests). `client_id`/
    | `client_secret` identify the OAuth app that requested consent;
    | `account_id`/`location_id` identify which GBP account/listing to sync
    | reviews from. All five commonly stay blank until that consent grant
    | happens — the command fails fast (logged, no partial sync) rather
    | than attempting a call with a blank credential.
    |
    */
    'google_reviews' => [
        'client_id' => env('GOOGLE_REVIEWS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_REVIEWS_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_REVIEWS_REFRESH_TOKEN'),
        'account_id' => env('GOOGLE_REVIEWS_ACCOUNT_ID'),
        'location_id' => env('GOOGLE_REVIEWS_LOCATION_ID'),
    ],

];
