---
name: gap-resolution-pattern
description: How to triage a genuine design gap an implementing agent flags mid-build — when to fix directly vs escalate to project-architect vs let it ride as debt
metadata:
  type: feedback
---

**When an implementing agent flags a genuine gap instead of guessing, triage it into one of three buckets rather than defaulting to "escalate to project-architect" or "let it ride":** (1) narrow, mechanical, low-risk-to-reverse → route straight back to the same or an adjacent implementer agent with a tightly-scoped fix task; (2) touches a real architectural/data-model tradeoff with lasting consequences → project-architect first; (3) genuinely low-stakes / doesn't block the current phase's critical path → note it as tracked debt in the phase memory and move on.

**Why:** confirmed working well on Phase 4's build session (2026-09-22) — five real gaps surfaced (`Payment.method` sourcing, refund double-click idempotency, an Inertia/JSON error-shape mismatch, a missing suburb-lookup endpoint blocking checkout, and a booking-cancel/order-cancel coupling question), all bucket (1), all closed same-session with a single tightly-scoped follow-up task each, none needed project-architect. Escalating all of these would have stalled the phase on project-architect availability for questions that had one clearly-reasonable answer once actually looked at; silently absorbing them into an implementer's own judgment (the failure mode root `CLAUDE.md` warns against) would have risked exactly the class of bug two of them actually were.

**How to apply:** when an agent's handback includes a "flagged, not guessed" gap, read it closely enough to classify it yourself before deciding whether to write a project-architect brief or just write a tighter follow-up task. Signs it's bucket (1): the fix is additive (a new endpoint/column-population, not a schema change), doesn't reverse an existing decision, and the "obviously right answer" is derivable from docs/existing code patterns already in the repo (e.g. "mirror how X already does Y"). Signs it's bucket (2): it would change a data model field's meaning, reverse a stated architecture decision, or introduce a new third-party dependency.

**A second, related pattern that held up well this session: after a security-relevant fix, get the *same reviewing agent* (not just the fixing agent's own say-so) to re-verify before calling it closed** — and instruct that reviewer to independently re-run the actual test/PHPStan/Pint commands itself, not trust the fixing agent's reported numbers. This caught nothing wrong on Phase 4 (both fixes were correct), but it's the same "don't let a self-report substitute for verification" discipline qa-lead has held to in every prior phase — worth carrying into every future money/auth/PII fix-then-verify loop, not just phase-end sign-off.

See [[phase4-cart-checkout-payments]] for the concrete instance this pattern was drawn from.
