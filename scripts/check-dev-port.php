<?php

/**
 * Pre-flight checks for `composer run dev` (see composer.json's "dev" script).
 *
 * CHECK 1 -- port collision (hard failure, exit 1). WHY THIS EXISTS
 * (2026-09-25): backend-tester found three concurrent `composer run dev`
 * instances running at once during a Phase 7 test pass. `php artisan dev`
 * (Laravel's built-in dev command, see
 * vendor/laravel/framework/src/Illuminate/Foundation/DevCommands.php)
 * starts `php artisan serve` (PHP's built-in server, port 8000 by
 * default), `php artisan queue:listen`, `php artisan pail`, and `npm run
 * dev` (Vite) all at once. A second concurrent instance means two `serve`
 * processes fighting over the same port, and -- worse than a port clash
 * error -- two `queue:listen` workers silently double-processing the same
 * queued jobs. This is easy to end up with by accident on a shared dev
 * machine with multiple terminals / multiple agent sessions running
 * against the same backend/ checkout (see also scripts/test-db.sh, which
 * solves the analogous "concurrent runs trample each other" problem for
 * the test database).
 *
 * This only checks the PHP dev server's port (8000 by default, or
 * APP_PORT if set, matching `php artisan serve`'s own --port default and
 * env override) -- that's the port both `tests/e2e/*.spec.ts` (Playwright,
 * see playwright.config.ts) and normal browser access hit directly, and
 * where a second `serve` colliding is a hard, confusing failure. It does
 * NOT check Vite's port (5173): Vite already auto-increments to the next
 * free port on its own when 5173 is taken, so it degrades gracefully
 * rather than silently colliding -- no guard needed there.
 *
 * This is a best-effort heuristic, not a lock: it only catches the common
 * case (a previous `composer run dev` still bound to the port). If you're
 * confident this is a false positive -- e.g. something unrelated to this
 * project is using the port -- run `php artisan dev` directly to bypass
 * this check.
 *
 * CHECK 2 -- native queue:listen vs. the Compose `queue-worker` service
 * (soft warning only, does not block). WHY THIS EXISTS (2026-09-25,
 * confirmed live on this exact machine while writing this check: `docker
 * ps` showed `mts-queue-worker-1` already running alongside two orphaned
 * native `php artisan serve` processes on :8000): `../docker-compose.yml`'s
 * `queue-worker` service is a persistent `php artisan queue:work
 * --queue=otp-mail,notifications,default` container (`restart:
 * unless-stopped`) pointed at the same Redis instance backend/.env's
 * native REDIS_HOST=127.0.0.1:6379 also reaches (the compose `redis`
 * service forwards that same port to the host). Neither backend/.env nor
 * the `queue-worker` service overrides APP_NAME/REDIS_PREFIX, so both
 * resolve the identical default Redis key prefix
 * (config/database.php:152, `Str::slug(APP_NAME).'-database-'` ==
 * `laravel-database-` while APP_NAME is still the unchanged "Laravel"
 * default) -- i.e. they are, right now, the same logical queue. `php
 * artisan dev`'s built-in `queue:listen` (started by `composer run dev`
 * on any OS where pcntl is unavailable for `pail`, which includes native
 * Windows -- true on this machine) is therefore a SECOND consumer racing
 * that same container for the same jobs (OTP emails, booking
 * notifications) any time both are up together -- real risk of a customer
 * getting a duplicate notification, not just wasted work.
 *
 * There's no clean way to exclude just `queue:listen` from `php artisan
 * dev` without an app-code change (Laravel's `DevCommands::except('queue')`
 * has to run from a service provider's boot method -- deliberately NOT
 * done here, since app/Providers/AppServiceProvider.php had unrelated
 * uncommitted changes in-flight from another session at the time this was
 * written; that's a backend-agent follow-up, not something to force from
 * this script). This check only warns, so you can decide per-session
 * whether to `docker compose stop queue-worker` before running `composer
 * run dev`, or leave both running and accept the (currently rare, but
 * real) chance of a duplicate send.
 */
$host = '127.0.0.1';
$port = (int) (getenv('APP_PORT') ?: 8000);

$connection = @fsockopen($host, $port, $errno, $errstr, 0.5);

if ($connection !== false) {
    fclose($connection);

    fwrite(STDERR, <<<TEXT

    [dev] Port {$port} on {$host} is already in use.
    [dev] Another 'composer run dev' (or 'php artisan serve') is likely
    [dev] already running for this project. Refusing to start a second
    [dev] instance: it would fight the first one for the same port and
    [dev] cause queued jobs to be double-processed by two queue:listen
    [dev] workers at once.
    [dev]
    [dev] Stop the other instance first, then retry 'composer run dev'.
    [dev] If you're sure this is a false positive, run 'php artisan dev'
    [dev] directly to bypass this check.

    TEXT);

    exit(1);
}

$dockerOutput = @shell_exec('docker ps --filter "name=queue-worker" --format "{{.Names}}"');
$dockerOutput = is_string($dockerOutput) ? trim($dockerOutput) : '';

if ($dockerOutput !== '') {
    fwrite(STDERR, <<<TEXT

    [dev] Heads up: the Compose 'queue-worker' container ({$dockerOutput}) is
    [dev] already running and consuming the same Redis queue this session's
    [dev] native 'queue:listen' is about to start consuming too (both read
    [dev] backend/.env's default, unprefixed Redis connection -- see this
    [dev] script's header comment for the full mechanics). This won't block
    [dev] 'composer run dev', but jobs (OTP emails, booking notifications)
    [dev] may get double-processed while both are up. Run
    [dev] 'docker compose stop queue-worker' first if you want a single
    [dev] consumer for this session.

    TEXT);
}

exit(0);
