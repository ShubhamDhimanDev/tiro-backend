<?php

use App\Support\DevQueueGuard;

/**
 * `App\Support\DevQueueGuard` — see its own docblock for the full incident
 * this follows up on. Only the two short-circuit guard branches are
 * exercised here (not running in console; running in console but the
 * invoked command isn't `dev`) — neither of those branches shells out to
 * `docker`, so they're safe and deterministic to test directly. The actual
 * `docker ps` detection branch is intentionally left untested, matching
 * `scripts/check-dev-port.php`'s own identical detection logic, which has
 * no test coverage for the same reason: it depends on a real Docker daemon
 * being present, which this suite cannot assume.
 */
it('is false when not running in console, regardless of argv', function () {
    expect(DevQueueGuard::shouldExcludeNativeQueueListener(false, ['artisan', 'dev']))->toBeFalse();
});

it('is false in console when the invoked command is not "dev"', function () {
    expect(DevQueueGuard::shouldExcludeNativeQueueListener(true, ['artisan', 'migrate']))->toBeFalse();
    expect(DevQueueGuard::shouldExcludeNativeQueueListener(true, ['artisan', 'queue:work']))->toBeFalse();
    expect(DevQueueGuard::shouldExcludeNativeQueueListener(true, []))->toBeFalse();
});
