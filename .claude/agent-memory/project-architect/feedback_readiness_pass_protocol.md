---
name: feedback-readiness-pass-protocol
description: How the user wants each phase's pre-handoff architecture readiness pass conducted — established over 5+ phases (Phase 1 through Phase 5 promotions/pricing).
metadata:
  type: feedback
---

Before each new phase hands off to backend-agent/super-admin-agent/frontend-agent, the user asks for a fast **implementation-readiness pass**, not a redesign. Confirmed pattern across Phases 1–5, each time the same shape of request.

**What "readiness pass" means concretely:**
1. Read the phase's dedicated architecture doc in full, plus the relevant slices of `01-data-model.md`, `02-api-contract.md`, `06-open-decisions.md`, the requirements doc's matching section, and the task-breakdown row for that phase.
2. Explicitly re-check any convention flagged in an earlier phase as "reusable in this phase" (e.g. `HasHold`, `AdminGuard`, `AuditLog`, the idempotent-secret-replay pattern) — confirm it's still concrete and actually implemented (grep the code, don't trust the doc's claim alone), don't let an implementer reinvent it.
3. Verify RBAC by reading `RolesAndPermissionsSeeder.php` directly, never assume the matrix is clean — this project has hit retrofits before (Phase 2 needed one).
4. Where the docs are genuinely vague (not just informally worded but actually ambiguous enough that two agents could implement differently), **design the missing piece concretely and write it directly into the docs** — exact algorithms, exact field additions, exact route shapes. Don't leave "the workflow exists" without specifying the mechanism.
5. Where two similar-but-distinct mechanisms could collide (e.g. two different "hold" concepts, two different TTLs), reason through the interaction explicitly and write the resolution down — this project has been burned by exactly this shape of ambiguity before (see [[project-hold-mechanism-convention]]).
6. Confirm migrations are genuinely net-new by checking the actual migrations directory, not by trusting the doc's field sketch.
7. Explicitly call out which pieces need security-agent's eyes before sign-off (don't leave discovery to sign-off time) — the user names likely candidates in the task prompt itself (e.g. "can a client-manipulated request apply an unauthorized discount") and expects those to be confirmed/addressed, not just repeated back.
8. Flag genuinely unrequested/speculative scope rather than building it defensively — e.g. Phase 5's promo-code-entry field was never asked for in requirements, so the right call was "don't build it, note why, flag as decision #18," not silently adding it "just in case."
9. Fix real gaps **directly in the docs** (Edit/Write), not just in the final report — the report is a summary of what changed, not the deliverable itself.

**Report shape expected back:** concise — what was found/designed, the one hardest interaction problem resolved (explicitly named as the thing most needed before handoff), RBAC check result, and a plain "implementation-ready: yes/no" verdict. Not an essay.

**Why:** the user runs `project-manager` as the next step after this pass and doesn't want backend-agent/super-admin-agent/frontend-agent guessing at anything this pass could have resolved — cost of reversal is the explicit lens (e.g. mutually-exclusive-by-default promotions because loosening later is cheap, stacking exploits are not).
