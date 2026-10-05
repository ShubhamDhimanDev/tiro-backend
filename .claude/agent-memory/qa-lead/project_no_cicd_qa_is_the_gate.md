---
name: project-no-cicd-qa-is-the-gate
description: There is no CI/CD anywhere in this workspace — qa-lead's own independently-reproduced test runs are the only quality gate before code reaches the Hestia VPS.
metadata:
  type: project
---

Neither `backend/` nor `frontend/` has any CI/CD (stated explicitly in the root `CLAUDE.md`: "No CI/CD. Nothing runs automatically on push. Tests, linting, and deploys are all manual/scripted."). Nothing else in the pipeline catches a regression before production — Hestia VPS deploys are manual/scripted with no automated gate of their own.

**Why:** This makes qa-lead's sign-off the actual, only checkpoint — not a formality layered on top of an automated pipeline. Standing practice since Phase 1 is qa-lead personally re-running the full backend (`composer test` / `scripts/test-db.sh`) and frontend (`npm run lint`, `npm run build`, `npx vitest run`) suites and reading the actual new test files, not summarizing a tester's self-report.

**How to apply:** Never treat a tester's reported pass/fail counts as sufficient — always reproduce them. See [[feedback_verify_dont_trust_reports]] for a concrete case where this caught two real gaps that a summary alone would have missed. When withholding sign-off, be specific about what's missing and route it back to the exact agent responsible, not a vague "needs more work."
