---
name: phase4-cart-checkout-payments
description: Phase 4 (Cart, Checkout & Payments) scope, the Stripe-test-mode-vs-BNPL-approval distinction, and what's already designed
metadata:
  type: project
---

Phase 4 kicked off 2026-09-22, immediately after Phase 3 (Booking & Capacity Engine) was signed off and fully closed. project-architect ran an implementation-readiness pass the same day — see `docs/architecture/01-data-model.md` Commerce section, `02-api-contract.md`'s "Cart, Checkout & Payment endpoints", `03-integrations.md` item 3, `06-open-decisions.md` items 5/7/17, `07-admin-auth-permissions.md` §3.2.

**The headline finding:** Stripe test-mode API keys (self-serve, any developer, zero business approval — dashboard.stripe.com, test mode) cover essentially all of Phase 4's engineering scope — cards, Apple Pay, Google Pay, and **native Afterpay via Stripe's Payment Element**, end-to-end including webhook delivery (`stripe listen` locally) and refunds. Only *flipping Afterpay to live keys at actual launch* is blocked on merchant approval (open decision #5). **Zip is a separate, more uncertain case** — not part of Stripe's integration, needs its own account, sandbox-without-approval status unconfirmed — treated as a follow-on integration, not Phase 4 critical path.

**Confirmed via repo scan 2026-09-22 (still true — no Stripe keys in `backend/.env` or `.env.example`, no `stripe/stripe-php` in `composer.json`, no `Address`/`Order`/`Payment`/`OrderLineItem` migrations exist):** this phase's backend work genuinely starts from zero on Commerce entities. `Customer`/`EmailOtpChallenge` are the only Commerce-adjacent entities that shipped early (Phase 0's auth slice).

**Design decisions already locked, not to be redesigned:**
- No server-side `Cart` entity — Phase 3's `Booking`/`BookingLineItem` becomes the durable cart once an appointment is chosen. `Order.booking_id` required+unique enforces one order per booking.
- `POST /api/v1/cart/calculate` is stateless, two input modes (raw items pre-booking vs `{booking_id}` at checkout), same computation both times.
- Order creation rides the booking's existing 15-min hold — no second hold-with-TTL. Webhook must defensively re-check booking status before confirming (`Order.status = refund_required` edge case when payment lands after hold expiry).
- Guest order continuity reuses `Booking.manage_token`'s exact pattern (`Order.guest_token_hash`/one-time `order_token`, same 15-min encrypted side-cache, same `*_issued` convention) — supersedes an older signed-cookie idea.
- Webhook: mandatory `Stripe\Webhook::constructEvent` signature verification; replay-safety is event-id cache (24h) + DB backstop via unique `Payment.gateway_reference` — a *different* concern from the client `Idempotency-Key` pattern.
- `POST /api/v1/orders` reuses the existing `Idempotency` middleware (`backend/app/Http/Middleware/Idempotency.php`) unmodified.
- Admin refund: Stripe SDK-native Idempotency-Key on the Refund API call, new `Payment` row (`type=refund`), `AuditLog` entry.
- Money convention: **all prices GST-inclusive** (AU retail convention) — `tax_total` is an extracted/informational breakdown (`round((subtotal − discount_total + service_fee_total) / 11)`), never additive to `grand_total`. Getting this backwards is a real landmine per the architecture doc.
- `orders.refund` is a new standalone permission (Super Admin + Operations only, **not** Customer Support despite CS holding `orders.manage`) — `orders.view`/`orders.manage` already existed from Phase 0's seeder, confirmed clean, no retrofit needed there.

**Practical implication:** backend-agent needs real Stripe test-mode keys in `.env` to test the payment/webhook flow end-to-end — free and self-serve, but someone has to actually create the account and drop `STRIPE_SECRET_KEY`/`STRIPE_PUBLISHABLE_KEY`/`STRIPE_WEBHOOK_SECRET` into `.env`. Mocked/unit tests can proceed without them. Flag this plainly each time rather than assuming keys already exist — confirmed absent as of 2026-09-22 kickoff.

See [[project-structure]] for doc file locations and [[delegation-conventions]] for how sign-off/testing gets routed this phase.

## Status as of 2026-09-22 build session

**Done and independently verified this session:** full backend slice (migrations, `cart/calculate`, `POST /api/v1/orders` + Stripe PaymentIntent, webhook handler, admin refund mechanism, `orders.refund` RBAC), a `GET /api/v1/suburbs` lookup endpoint added mid-phase (see gap note below), the Orders admin module (search/view/cancel/status-update/refund UI, `super-admin-agent`), and the storefront cart/checkout/confirmation/guest-tracking UI (`frontend-agent`). security-agent reviewed the backend slice twice (once initial, once re-verifying two fixes) and independently ran the test/PHPStan/Pint commands itself both times rather than trusting the self-reports.

**Real bugs found and fixed in-session, not deferred:**
- Webhook cache-dedup marked an event "handled" *before* processing completed — a handler throw (reachable via an unmapped Stripe payment-method type) meant Stripe's automatic redelivery hit the cache short-circuit and never reprocessed, permanently stranding a paid order in `pending_payment`. Fixed: cache is now written only after successful dispatch.
- Admin refund's Idempotency-Key was server-generated fresh per HTTP request, so a double-click produced two real Stripe refunds for a partial-refund amount (full refunds were incidentally protected by Stripe's own reject-if-fully-refunded behavior, partials weren't). Fixed: client-supplied key now required, looked up by `(order_id, idempotency_key)` before any Stripe call — same convention as bookings/orders creation. **The money-safety guarantee here depends entirely on the client generating the key once per refund-*intent* (e.g. dialog-open) and reusing it across retries** — super-admin-agent implemented this correctly via a `useRef` keyed on dialog-open, verified in its own report.
- Follow-on UX fix: the above reuses the JSON-API `Idempotency` middleware verbatim on an Inertia admin route, which broke Inertia's normal validation-error flow (raw JSON 422 instead of `back()->withErrors()`). Fixed with an `X-Inertia`-aware branch in the shared middleware, JSON API routes unchanged.
- `Payment.method` was hardcoded to `Card` at order-creation and never updated (Stripe's Payment Element only reveals the real method at client confirmation, after the row already exists). Fixed by resolving the real method from a live `paymentMethods->retrieve()` call inside the `payment_intent.succeeded` webhook handler — required verifying Stripe's actual payload shape (webhook events never carry expanded properties) rather than guessing.
- Checkout was structurally blocked: no endpoint resolved a Google Places address into the `suburb_id` `POST /api/v1/orders` requires. Closed with a new `GET /api/v1/suburbs?postcode=&name=` lookup, then wired into the frontend.

**Genuinely external blockers, not functionality gaps:** no Stripe test-mode keys (`STRIPE_SECRET_KEY`/`STRIPE_PUBLISHABLE_KEY`/`STRIPE_WEBHOOK_SECRET`) exist anywhere in this environment — free/self-serve at dashboard.stripe.com, someone just has to create the account. No `NEXT_PUBLIC_GOOGLE_MAPS_API_KEY` either (frontend degrades to manual address entry without it). Until both exist, nothing in this phase has been verified against a real payment or real autocomplete — only against test doubles.

**Still queued, not started as of this checkpoint:** qa-lead-led dedicated test coverage (idempotent retry, webhook signature/replay, refund partial+full, permission-gate denial — beyond what backend-agent/security-agent already wrote inline), a security-agent look specifically at the new `RefundDialog` frontend idempotency-key handling (super-admin-agent requested this itself), and final phase sign-off.

See [[gap-resolution-pattern]] for how design gaps got triaged this session (worth reusing next phase).
