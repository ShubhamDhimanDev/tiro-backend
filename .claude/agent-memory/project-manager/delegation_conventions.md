---
name: delegation-conventions
description: How sign-off, testing delegation, and commit discipline work on this project — conventions confirmed across Phases 0-4
metadata:
  type: feedback
---

**qa-lead owns test delegation — don't call backend-tester/frontend-tester directly for phase test coverage.** Per root `CLAUDE.md`'s roster table, qa-lead "delegates to the two testers itself." As project-manager I should hand qa-lead the phase's test-coverage requirements and let it decide how to split work between the two testers, rather than briefing testers myself.

**Why:** qa-lead is the one that runs full suites and gives/withholds sign-off; if I bypass it and brief testers directly, qa-lead loses the coordinating context it needs to give an honest phase-level verdict.

**How to apply:** When a phase reaches the point where real functionality exists to test, delegate to qa-lead with the phase's specific test-coverage asks (e.g. "idempotent retry behaviour, webhook signature/replay handling, refund gate denial") rather than spawning backend-tester/frontend-tester myself.

---

**security-agent gets flagged upfront on money/auth/PII-touching rows, not discovered only at sign-off.** Confirmed as an explicit project-architect correction for Phase 4 ("this phase's flagged-upfront items... not left to be discovered at sign-off this time"). Past phases sometimes let security review happen only as a final gate; Phase 4's readiness pass explicitly named the security-relevant pieces (webhook signature verification, guest token reuse, refund idempotency+permission gate) before implementation started.

**Why:** payment/PII risk is expensive to unwind after the fact — better to have security-agent review the specific risky mechanism right after it's built, not batched into one end-of-phase audit.

**How to apply:** For any phase touching auth/payments/PII, identify the specific risky mechanisms in the architecture docs' own language and route security-agent at that specific checkpoint, not just as a final gate before qa-lead sign-off.

---

**Never create git commits unless explicitly asked** — explicit root `CLAUDE.md` constraint reiterated per-phase in user briefs. Applies to me and everything I delegate, regardless of whether a `.git` repo exists.

**Why:** stated project constraint, the user's call to make, not mine to relitigate either direction (neither "commit without being asked" nor "assume there's nothing to commit").

**How to apply:** Never instruct an agent to `git commit` unless the user explicitly asks, even when there are obviously-related changes sitting uncommitted. **`backend/` does now have a `.git` repo** (initialized + first-committed by the user directly after Phase 2, `bd4b9e6`/409 files — corrected 2026-09-22 in [[project-structure]] after this session's closing report repeated the stale "no .git" claim) — don't assume it's git-less without checking `git status` first, and don't assume the reverse either; re-verify each time rather than trusting either state from memory.

---

**This project's fragile-pattern convention: PHP backed enums with exhaustive `default => throw`, never a bare `match(true)` or string-based switch for domain enums.** Surfaced originally in Phase 2 (`RolesAndPermissionsSeeder::permissionNamesForTier()` had no default arm through three prior bugs before phpstan caught it), repeated as an explicit warning in every subsequent phase's new-enum rows (Phase 3's `BookingStatus`/etc., Phase 4's `OrderStatus`/`PaymentStatus`/`PaymentType`/`PaymentGateway`/`PaymentMethod`/`PaymentTransactionStatus`/`AddressType`).

**Why:** the same bug shape recurred three times before being caught; the project has adopted exhaustive-match-with-throw as a standing convention specifically to stop it recurring a fourth+ time.

**How to apply:** Whenever briefing backend-agent on new enums, explicitly call out this convention rather than assuming it's remembered from a prior phase — every phase's docs restate it because it keeps almost getting missed.
