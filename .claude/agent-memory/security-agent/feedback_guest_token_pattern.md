---
name: guest-token-pattern
description: How to review a new entity reusing Booking.manage_token's one-time-secret-under-idempotent-replay pattern, without re-deriving the whole design from scratch.
metadata:
  type: feedback
---

This project has a sanctioned, documented convention ("One-time secrets under idempotent replay", `docs/architecture/02-api-contract.md`) for issuing a one-way-hashed guest-continuity secret under an `Idempotency-Key`-guarded creation endpoint. `Booking.manage_token` was the original (Phase 3), `Order.guest_token_hash`/`order_token` (Phase 4) reuses it "unmodified" per the docs.

**Why:** when a phase brief says a mechanism is a deliberate reuse of an already-reviewed pattern, the ask is to *confirm faithfulness*, not re-derive the design's soundness from first principles again. The fast way to verify: grep the new model for the equivalent method names (`hashGuestToken`/`guestTokenMatches`/`guestTokenCacheKey`/`cacheGuestToken`/`cachedGuestToken` on `Order` vs. `hashManageToken`/`manageTokenMatches`/`manageTokenCacheKey`/`cacheManageToken`/`cachedManageToken` on `Booking`), and diff the substance: same TTL constant (ideally referencing the original constant, e.g. `Order::GUEST_TOKEN_TTL_MINUTES = Booking::HOLD_TTL_MINUTES`, not a re-typed `15`), same `Crypt::encryptString`/`decryptString` for the transient side-cache, same `hash_equals()` constant-time comparison, same "decrypt failure = treat as no cached token, not a hard error" posture.

**How to apply:** for any future entity that reuses this pattern (Phase 5+ likely candidates: anything issuing a guest-facing one-time secret), do the side-by-side method comparison above first — it's fast and catches drift (e.g. someone re-typing `24` hours instead of reusing the 15-minute constant, or using plain `===` instead of `hash_equals()`) — then move on to reviewing what's actually new about that phase's usage (route auth ordering, response shape) rather than re-litigating the cache/encryption design itself.
