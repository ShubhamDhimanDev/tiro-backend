---
paths:
    - composer.json
    - scripts/**
---

# Local dev server (`composer run dev`)

## Never run more than one `composer run dev` at once for this checkout

`composer run dev` invokes Laravel's built-in `php artisan dev`
(`vendor/laravel/framework/.../Foundation/DevCommands.php`), which starts,
concurrently: `php artisan serve` (PHP's built-in single-threaded server,
port 8000 by default), `php artisan queue:listen`, `php artisan pail`, and
`npm run dev` (Vite). A second concurrent instance doesn't just clash on
the `serve` port — it starts a second `queue:listen` worker, and two
workers will silently double-process the same queued jobs.

This is easy to end up with by accident on a shared dev machine with
multiple terminals or multiple agent sessions working against the same
`backend/` checkout at once — found happening (three concurrent instances)
during a Phase 7 backend-tester run, 2026-09-25.

`scripts/check-dev-port.php` is wired into the `dev` composer script as a
pre-flight guard: it checks whether `APP_PORT` (default 8000) is already
bound on `127.0.0.1` and refuses to start `php artisan dev` if so, with a
message pointing at the likely cause. It only checks the PHP server's
port — Vite (5173) already auto-increments to a free port on its own, so
no guard is needed there. This is a heuristic, not a lock (someone can
still bypass it by running `php artisan dev` directly) — before starting
a new `composer run dev`, check whether one is already running (another
terminal, another agent session) rather than relying on the guard alone.

## The Compose `queue-worker` container is a second, silent job consumer

Separately from the port collision above: the root `docker-compose.yml`
runs a persistent `queue-worker` service (`restart: unless-stopped`) that
consumes the exact same Redis queue `composer run dev`'s native
`queue:listen` also consumes — both resolve the same default Redis key
prefix from `backend/.env`'s unchanged `APP_NAME=Laravel`. Confirmed live,
not hypothetical, 2026-09-25 (`mts-queue-worker-1` found running via
`docker ps` while two native `serve` processes were also up). Same guard
script now warns (non-blocking) about this too. Full mechanics and the
real fix (exclude `queue:listen` from `php artisan dev` at the app-code
level — not yet done, see that script's header and the `queue-worker`
service's own comment block in `../docker-compose.yml`) are documented in
both of those places rather than duplicated here.
