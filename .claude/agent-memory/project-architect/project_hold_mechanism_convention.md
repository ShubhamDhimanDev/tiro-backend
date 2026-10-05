---
name: project-hold-mechanism-convention
description: The project's recurring "hold-with-TTL" pattern (App\Contracts\HasHold) and the reasoning discipline for when two similar-but-distinct hold/secret windows collide — has recurred 3x, will likely recur again.
metadata:
  type: project
---

This codebase has one generic hold-with-TTL mechanism, `App\Contracts\HasHold` (`backend/app/Contracts/HasHold.php`), built in Phase 3 for `Booking`'s technician-slot hold: `Cache::lock()` for race prevention at creation time, delayed job + every-minute sweep (`config('holds.models')`, `ReleaseExpiredHoldsCommand`) for eventual release. Designed explicitly to be reused, not reimplemented, by future holdable concepts.

**It has now recurred three times, each with a different resolution — the pattern to apply going forward:**

1. **`Booking`'s own technician-slot hold** (Phase 3) — the original, canonical implementor. Independently registered in `config('holds.models')`.
2. **`Booking.manage_token` / `Order.guest_token_hash`** (Phase 3/4) — not a `HasHold` implementor (it's a one-time secret, not a scarce resource), but solves an adjacent "two windows" problem: the *permanent* idempotency-dedup guarantee (unique DB column, unbounded) had to be split from a *separate, narrower* 15-minute secret-replay guarantee (encrypted side-cache). Locked in [02-api-contract.md](../../../docs/architecture/02-api-contract.md)'s "One-time secrets under idempotent replay" section. The TTL is 15 minutes **because** it's structurally the same window as the booking hold it's always attached to, not a coincidentally-equal constant.
3. **`PromotionRedemption` as the promo-stock hold** (Phase 5) — implements `HasHold` for interface consistency, but **deliberately not registered in `config('holds.models')`**. Its `hold_expires_at` is always set equal to its parent `Booking.hold_expires_at` (same "structurally identical window" reasoning as #2), so an independent sweep registration would create two sweep paths that must always agree on timing for the same event — instead `Booking::releaseHold()` cascades to release its own held `PromotionRedemption` children. Full reasoning: [05-promotions-pricing.md](../../../docs/architecture/05-promotions-pricing.md)'s "Promo stock-limit enforcement" section.

**The reusable lesson, apply to any future hold/secret/TTL concept (e.g. a future inventory-reservation hold):** don't reflexively register a new holdable model in `config('holds.models')` just because it implements `HasHold` — first ask whether its expiry window is *independent* (register it, own sweep entry) or *structurally derived from* an already-swept parent's window (cascade-release from the parent instead, to avoid two mechanisms that must stay in sync). This distinction is exactly what [[feedback-readiness-pass-protocol]] means by "two similar-but-distinct hold concepts colliding" — always reason through it explicitly rather than defaulting to "just reuse the config list."
