---
name: project-structure
description: Where architecture docs and agent roster live for the Tiro Mobile Tyres rebuild; this project's per-phase security-review practice.
metadata:
  type: reference
---

- Root workspace: `C:\zzz-shubham\MTS`. Two independently-git-repo'd apps: `backend/` (Laravel 13 + Inertia/React Super Admin panel) and `frontend/` (Next.js storefront). Root `CLAUDE.md` documents the agent roster and hosting constraints.
- Architecture/design docs live at **root level**, not under `backend/`: `C:\zzz-shubham\MTS\docs\architecture\`. Key files seen so far:
  - `01-data-model.md` — entity field/index specs (Commerce section covers `Order`/`Payment`/`Address`, added 2026-09-22 for Phase 4).
  - `02-api-contract.md` — Laravel↔Next.js API contract, idempotency conventions, "One-time secrets under idempotent replay" convention (§ added 2026-09-21), webhook security/replay-safety design.
  - `06-open-decisions.md` — numbered blockers/decisions table, each with a Blocks/Notes column; items get resolved in place rather than removed, so history is legible.
  - `07-admin-auth-permissions.md` — Fortify/spatie RBAC design, permission matrix (§3), 2FA enforcement (§4), decision records for standalone permissions like `orders.refund` (§3.2).
  - `08-customer-auth-otp.md` — storefront `Customer` auth (Sanctum, password+OTP), §10 covers guest-checkout create-or-match-by-email semantics.
- **Standing practice**: this project flags specific mechanisms for security-agent review *upfront*, per-phase, rather than doing one end-of-project audit — e.g. Phase 3 flagged `Booking.manage_token`/Redis hold-TTL, Phase 4 flagged Stripe webhook verification + guest `order_token` reuse + refund idempotency. When a new phase reuses a previously-reviewed mechanism (e.g. `Order.guest_token_hash` reusing `Booking.manage_token`'s pattern), the ask is explicitly to confirm faithful reuse, not re-derive the whole mechanism from scratch — see [[feedback_guest_token_pattern]].
- Backend has **no git repo** across every phase reviewed so far (confirmed each time) — always read current files directly, never assume a diff is available.
- `backend/CLAUDE.md` is Laravel-Boost-generated (framework/convention guidance: Pint, Pest, Wayfinder, Inertia v3). Always read it fresh at the start of a backend review — it's regenerated, not hand-maintained.
