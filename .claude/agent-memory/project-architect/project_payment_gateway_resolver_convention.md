---
name: project-payment-gateway-resolver-convention
description: Why PaymentGateway::class (active-gateway singleton) and PaymentGatewayResolver (per-Payment-row resolution) are two deliberately different bindings, added when PayPal became a second gateway alongside Stripe (2026-09-28).
metadata:
  type: project
---

`backend/app/Contracts/Payments/PaymentGateway.php` is bound in `AppServiceProvider::register()` as a lazy singleton resolved from `config('services.payment_gateway')` (env `PAYMENT_GATEWAY`, default `stripe`). This binding is **only correct for creating a brand-new payment** (`OrderController::store()`'s create branch) — it reflects whichever gateway is currently active, nothing more.

**The gap this closed:** `RefundService` and any webhook/reconciliation code operate on an **already-persisted** `Payment` row, which may have been created under a gateway that is no longer the active one (the merchant flipped `PAYMENT_GATEWAY` at some point after that order was placed). Injecting the active-gateway singleton into `RefundService` would silently call the wrong gateway's API against a foreign reference id the first time a historical refund is requested after a gateway switch — a real, easy-to-miss correctness bug, not a hypothetical.

**Resolution:** a second, deliberately separate mechanism, `App\Services\Payments\PaymentGatewayResolver::resolve(PaymentGateway $gateway): PaymentGateway` — a plain concrete class (not an interface), keyed off a `Payment` row's own `gateway` column, not off config. Call-site rule:
- New payment (nothing persisted yet) → inject the active `PaymentGateway::class` singleton.
- Anything touching an existing `Payment` row (refunds, reconciliation) → resolve via `PaymentGatewayResolver` off that row's `gateway` value.
- Webhook controllers are inherently single-gateway (Stripe's webhook only ever processes Stripe events) and must depend on the **concrete** gateway class directly, never either of the above.
- One accepted, narrow exception: `OrderController::store()`'s own Idempotency-Key-replay branch keeps using the active singleton even though it's technically re-reading an existing row — the replay window is seconds-to-minutes, not enough for a gateway switch to plausibly land in between.

Full writeup: [03-integrations.md](../../../docs/architecture/03-integrations.md)'s PayPal section, point 3. Schema side (`Payment.gateway_capture_reference`, needed because PayPal refunds target a capture id, not the order id — a second, related but distinct gap in the same area): [01-data-model.md](../../../docs/architecture/01-data-model.md)'s `Payment` section.

**The reusable lesson, same shape as [[project-hold-mechanism-convention]]:** whenever a global "currently active X" config-driven singleton exists alongside per-row historical data that was created under a *previous* value of that config, don't let the global singleton get silently reused for the historical-row code path — reason through whether that call site needs "active" or "the value this specific row was actually created under," explicitly, rather than defaulting to whatever's already injected. Will likely recur if a third payment gateway is ever added, or anywhere else this project introduces a config-selected implementation swap (e.g. a future SMS-provider failover) alongside data that outlives a single config value.
