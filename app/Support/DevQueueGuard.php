<?php

namespace App\Support;

/**
 * Decides whether `php artisan dev` (via `composer run dev`) should exclude
 * its bundled native `queue:listen` process — see
 * `AppServiceProvider::configureDevCommands()` for the call site and
 * `scripts/check-dev-port.php`'s header comment for the full incident this
 * follows up on (2026-09-25, confirmed live: the Compose `queue-worker`
 * container and `php artisan dev`'s own `queue:listen` silently race as two
 * consumers of the same queue (the shared `database` queue by default, or
 * Redis when opted in) — real risk of a customer getting a duplicate
 * notification).
 *
 * **Conditional, not unconditional** — this is the deliberate call, not an
 * oversight: an unconditional exclusion would silently stop OTP emails and
 * booking notifications from ever being processed for any dev session that
 * ISN'T also running the Compose stack (a currently-real, currently-used
 * workflow — `check-dev-port.php`'s own incident report found native
 * `php artisan serve` processes running without a queue-worker container
 * up), trading a "sometimes duplicated" bug for an "always silently
 * broken" one. The exclusion only applies when the Compose `queue-worker`
 * container is actually detected running, in which case it's the single
 * consumer and the native listener would be pure redundancy — mirrors
 * `scripts/check-dev-port.php`'s own `docker ps --filter "name=queue-worker"`
 * detection exactly, so both scripts agree on what "already covered by
 * Compose" means.
 */
final class DevQueueGuard
{
    /**
     * @param  list<string>  $argv  `$_SERVER['argv']` — checked directly
     *                              against, rather than resolving the
     *                              console kernel's parsed input, since
     *                              this runs from `AppServiceProvider::boot()`,
     *                              before Laravel has necessarily finished
     *                              resolving which Artisan command was
     *                              invoked.
     */
    public static function shouldExcludeNativeQueueListener(bool $runningInConsole, array $argv): bool
    {
        // Scoped to exactly the `dev` command — this shells out to `docker
        // ps` below, which must not run on every other Artisan invocation
        // (migrate, tinker, the queue worker itself, etc.) or every web
        // request; both would add real, needless latency/reliability risk
        // for a check that's only meaningful here.
        if (! $runningInConsole || ! in_array('dev', $argv, true)) {
            return false;
        }

        return self::queueWorkerContainerIsRunning();
    }

    /**
     * Identical detection logic to `scripts/check-dev-port.php`'s own
     * "Check 2" — kept as a plain, unmemoized shell-out (not cached) since
     * this is called at most once per `php artisan dev` invocation, not in
     * a hot path.
     */
    private static function queueWorkerContainerIsRunning(): bool
    {
        $output = @shell_exec('docker ps --filter "name=queue-worker" --format "{{.Names}}"');

        return is_string($output) && trim($output) !== '';
    }
}
