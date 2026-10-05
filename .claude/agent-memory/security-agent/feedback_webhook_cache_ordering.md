---
name: webhook-cache-ordering
description: Standing check for this codebase - any cache-based dedup for webhook/async event processing must not mark an event "handled" before processing actually succeeds.
metadata:
  type: feedback
---

Found in Phase 4's `StripeWebhookController` (see [[project_phase4_commerce_review]]): `Cache::add(eventCacheKey, true, $ttl)` was placed *before* the event-handling code ran, with no rollback on exception. Any exception during handling (including a legitimate, intentionally-thrown one, like this codebase's own `default => throw` closed-vocabulary convention) permanently blocks that event from ever being reprocessed via the provider's redelivery mechanism, because the redelivery hits the "already handled" cache short-circuit before reaching the code that would actually do the work.

**Why this matters specifically in this codebase:** this project has a strong, deliberate convention of throwing loudly on unrecognized enum/vocabulary values (`PaymentMethod::fromStripe()`, `PaymentTransactionStatus::fromStripeStatus()`, the Vehicles-phase fragile-pattern note in `01-data-model.md`) rather than silently defaulting. That's the right call for data integrity, but it means webhook/async handlers in this codebase *will* throw in realistic, not just hypothetical, scenarios — so the surrounding retry/dedup plumbing has to be failure-safe by design, not just for network blips.

**How to apply:** when reviewing any webhook handler, queue job, or other at-least-once-delivery consumer in this codebase, check the order of operations: the "mark as done" step (cache key, DB flag, whatever) must come *after* the work that could fail, or be wrapped so a failure un-marks it. If a handler's docstring says "a handful of DB writes, never queued" (as this one did), don't take that as evidence it can't throw for other reasons (a live external API call added later, an enum-mapping throw) — check what the code actually does now, not what the comment assumed when it was written.
