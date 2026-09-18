<?php

/**
 * Item 2 of the Phase 0 auth security review: `sanctum.guard` must stay
 * empty. This app is Bearer-token only (no `statefulApi()`, no session
 * middleware on `/api/*`) — populating this with `web` would apply
 * session-cookie authentication to every Sanctum-driver guard, including
 * `customer`, the moment stateful-SPA mode is ever turned on for any
 * reason.
 */
it('keeps the sanctum stateful guard list empty', function () {
    expect(config('sanctum.guard'))->toBe([]);
});
