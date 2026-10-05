---
paths:
    - tests/e2e/**
    - playwright.config.ts
---

# Playwright E2E suite (`tests/e2e/*.spec.ts`)

## Always run with a single worker — never `--workers=N` with N > 1

This project's local dev server is PHP's built-in single-threaded server
(`php artisan serve`, started by `composer run dev` — see
`.ai/rules/dev-server.md`). Running the E2E suite with
Playwright's default multi-worker parallelism means multiple concurrent
browser contexts (each logging in as its own admin user) hit that single
PHP process at the same time, causing real cross-test session/auth state
bleed. Confirmed 2026-09-25 (backend-tester): this isn't specific to any
one spec — it breaks pre-existing specs in this suite too, not just newly
added ones.

`backend/playwright.config.ts` sets `workers: 1` and is auto-discovered by
`npx playwright test` as long as it's invoked from `backend/` (the
convention every spec file's own header comment documents) — so the
default invocation is already safe. Don't override it with an explicit
`--workers=N` flag on the CLI (a CLI flag wins over the config value and
would reintroduce the bleed).

This is the same underlying class of problem `scripts/test-db.sh` solves
for concurrent test-database access (see that script's own header
comment) — isolate/serialize instead of letting concurrent runs share
state that assumes single-tenancy.
