import { defineConfig } from '@playwright/test';

/**
 * Playwright config for `tests/e2e/*.spec.ts` (the admin-panel E2E suite).
 *
 * WHY `workers: 1` (found + fixed 2026-09-25, backend-tester): this repo's
 * local dev server is PHP's built-in single-threaded server (`php artisan
 * serve`, started by `composer run dev` / `php artisan dev` -- see
 * scripts/check-dev-port.php for the related "don't run two of these"
 * guard). A single-threaded PHP server processes one request at a time, so
 * Playwright's default multi-worker parallelism (concurrent browser
 * contexts, each logging in as its own admin user and hitting the server
 * at the same time) causes real cross-test session/auth state bleed --
 * confirmed to break not just new specs but pre-existing ones in this same
 * suite. Forcing a single worker serializes all E2E requests against the
 * one PHP process, the same way scripts/test-db.sh serializes DB access
 * to avoid a different flavor of "tests trample each other". If this
 * project ever moves local E2E runs onto a proper multi-worker server
 * (e.g. `php artisan octane` or a real FPM pool behind nginx in Compose),
 * this restriction can be revisited -- but don't remove it without
 * verifying against that setup first.
 *
 * This config intentionally does NOT define `webServer`: every spec's own
 * doc comment documents starting `composer run dev` manually first and
 * pointing `E2E_BASE_URL` at it (default `http://localhost:8000`) --
 * auto-starting a server here would diverge from that documented,
 * currently-working convention.
 */
export default defineConfig({
    testDir: './tests/e2e',
    workers: 1,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    reporter: 'list',
});
