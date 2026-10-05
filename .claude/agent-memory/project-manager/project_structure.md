---
name: project-structure
description: Where docs/plan and docs/architecture actually live relative to backend/ cwd, and repo layout facts
metadata:
  type: project
---

`docs/plan/` and `docs/architecture/` live at the workspace root (`C:\zzz-shubham\MTS\docs\...`), **not** inside `backend/`. My cwd as project-manager is `C:\zzz-shubham\MTS\backend`, so relative globs like `docs/plan/01-task-breakdown.md` resolve to nothing — must use the absolute root path (`C:\zzz-shubham\MTS\docs\...`).

**Why:** `backend/` and `frontend/` are independent git repos per root `CLAUDE.md`; the planning/architecture docs are workspace-level, shared across both.

**How to apply:** Always read `docs/plan/01-task-breakdown.md` and `docs/architecture/*.md` via the absolute `C:\zzz-shubham\MTS\docs\...` path, not relative to cwd.

Other structural facts:
- **Corrected 2026-09-22, mid-Phase-4:** `backend/` is no longer git-less. Earlier phases (through Phase 3) genuinely had zero commits — confirmed repeatedly, qa-lead had to substitute mtime-based scans for `git diff` as a result. The user/coordinator ran `git init` and made a first commit (`bd4b9e6`, 409 files) directly, after Phase 2, at the user's request — this was missed/not re-checked during Phase 3 and the start of Phase 4, so earlier memory (and this session's own Phase 4 closing report) incorrectly repeated the "no .git" claim as still-current fact. As of this correction, `git status` shows 116 changed/new files accumulated across Phase 3 + Phase 4 work, not yet committed. **The "never create git commits unless explicitly asked" constraint is unchanged** — a repo now existing doesn't change that instruction, it only means `git diff`/`git log` are now real, usable tools for verification (qa-lead should prefer them over mtime scans going forward) and that "no .git" must no longer be asserted as current fact without re-checking `git status`/`git log` first.
- Key reusable backend patterns worth pointing agents at by exact path rather than re-deriving:
  - `backend/app/Http/Middleware/Idempotency.php` — generic format-validation-only idempotency middleware (built Phase 3), reused unmodified by `POST /api/v1/orders` in Phase 4.
  - `backend/app/Models/Booking.php` + `backend/app/Http/Controllers/Api/V1/Bookings/BookingController.php` — the `manage_token`/`manage_token_hash` one-time-secret-under-idempotent-replay pattern (15-min encrypted side-cache, `*_issued` boolean convention) that `Order.guest_token_hash`/`order_token` reuses unmodified in Phase 4.
  - `backend/database/seeders/RolesAndPermissionsSeeder.php` — where new permissions (e.g. `orders.refund`) get added; must pass `'guard_name' => 'web'` explicitly on every `Role::create()`/`Permission::create()` call.
