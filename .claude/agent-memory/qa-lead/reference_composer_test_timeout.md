---
name: composer-test-timeout
description: composer's own default 300s process-timeout can be too short for the Pest suite as it grows — bypass it by invoking scripts/test-db.sh directly rather than raising Bash tool timeouts.
metadata:
  type: reference
---

`backend/composer.json`'s `test` script (`@php artisan config:clear`, `@lint:check`, `@types:check`, then `bash scripts/test-db.sh run -- php artisan test`) does NOT wrap the Pest step in `Composer\Config::disableProcessTimeout` (unlike the `dev` and `ci:check` scripts, which do). Composer's own internal process-spawn timeout defaults to 300 seconds, independent of whatever timeout you give the Bash tool. As of Phase 3 the full suite (428 tests) took ~310-630s depending on system load — right at or past that ceiling — so `composer test` intermittently fails with "The process ... exceeded the timeout of 300 seconds" even though Pint and phpstan (the earlier steps) had already reported clean.

**How to apply:** When `composer test` dies on a timeout (check the output — Pint/phpstan results usually already printed clean before the timeout hit), don't treat it as a failure. Re-run just the Pest step directly, bypassing composer entirely: `bash scripts/test-db.sh run -- php artisan test --compact`, with a generous Bash tool timeout (480s+ recommended, may need to background it — see `run_in_background`). See `[[reference_test_db_isolation]]` for what `test-db.sh` itself does. Report Pint/phpstan/Pest results as three separate confirmations rather than relying on one `composer test` invocation to produce all three.
